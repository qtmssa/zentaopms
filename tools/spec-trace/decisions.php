<?php
declare(strict_types=1);

function decisionHistory(PDO $db, array $freeze): array
{
    $rows = query($db, 'SELECT id,stage,scope,decision,target_revision,reviewer,evidence,mechanical_report,prior_decision,epic_decision,created_at FROM zt_spectrace_decision WHERE freeze_id=? ORDER BY id', [$freeze['id']])->fetchAll();
    $heads = [];
    $epicHead = null;
    foreach ($rows as &$row) {
        $row['basis'] = $freeze['basis'];
        $row['id'] = (int)$row['id'];
        foreach (['prior_decision','epic_decision'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int)$row[$field];
        }
        $row['evidence'] = json_decode($row['evidence'], false, 512, JSON_THROW_ON_ERROR);
        $row['mechanical_report'] = $row['mechanical_report'] === null ? null : json_decode($row['mechanical_report'], false, 512, JSON_THROW_ON_ERROR);
        $heads[$row['stage']][$row['scope']] = $row['id'];
        if ($row['stage'] === 'epic') $epicHead = $row['decision'] === 'accepted' ? $row['id'] : null;
    }
    unset($row);
    foreach ($rows as &$row) {
        $row['current'] = $row['id'] === $heads[$row['stage']][$row['scope']] && ($row['stage'] === 'epic' || $row['epic_decision'] === $epicHead);
    }
    unset($row);
    return $rows;
}

function mechanicalReport(object $r, ?string $submitter): ?object
{
    $file = getenv('TRACE_MECHANICAL_REPORT');
    if ($r->decision === 'changes_requested' && !$file) return null;
    demand(is_string($file) && is_file($file) && is_readable($file), 'trusted mechanical report required');
    $report = json_decode(file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
    demand($report instanceof stdClass && ($report->schema ?? null) === 1, 'invalid mechanical report schema');
    foreach (['product','epic','revision','basis','stage','scope','target_revision'] as $field) {
        demand(($report->{$field} ?? null) === $r->{$field}, "mechanical report mismatch: $field");
    }
    demand(property_exists($report, 'epic_decision') && $report->epic_decision === ($r->epic_decision ?? null), 'mechanical report mismatch: upstream decision');
    demand(in_array($report->status ?? null, ['failed','passed'], true), 'unknown mechanical status');
    if ($r->decision === 'accepted') demand($report->status === 'passed', 'mechanical checks incomplete');
    digestId($report->manifest_digest ?? null, 'manifest digest');
    if ($r->stage === 'epic') {
        demand(($report->review_evidence_digest ?? null) === digest($r->evidence), 'independent expectation evidence mismatch');
    } else {
        $author = label($report->spec_author ?? null, 'spec_author', 128);
        demand($author === $submitter, 'mechanical report spec_author differs from recorded submitter');
    }
    return $report;
}

function appendDecision(PDO $db, object $r, array $freeze, string $actor): array
{
    $basis = digestId($r->basis ?? null, 'basis');
    demand(hash_equals($freeze['basis'], $basis), 'frozen basis mismatch');
    demand($freeze['author'] !== $actor, 'author cannot self-review');
    demand(in_array($r->stage ?? null, ['epic','spec'], true), 'invalid stage');
    demand(in_array($r->decision ?? null, ['accepted','changes_requested'], true), 'invalid decision');
    $evidence = $r->evidence ?? null;
    demand((is_string($evidence) && trim($evidence) !== '') || ($evidence instanceof stdClass && get_object_vars($evidence) !== []), 'independent evidence required');
    $scope = label($r->scope ?? null, 'scope');
    $target = label($r->target_revision ?? null, 'target_revision', 128);
    $prior = $r->prior_decision ?? null;
    if ($prior !== null) positive($prior, 'prior_decision');
    $upstream = $r->epic_decision ?? null;
    if ($r->stage === 'epic') {
        demand($scope === $freeze['epic'] && $upstream === null, 'invalid epic scope');
        $baseline = json_decode($freeze['graph'], false, 512, JSON_THROW_ON_ERROR)->baseline;
        demand($target === $baseline, 'epic target must equal frozen Git baseline');
    } else {
        digestId($target, 'spec target_revision');
        positive($upstream, 'epic_decision');
        demand((bool)query($db, "SELECT 1 FROM zt_spectrace_member WHERE freeze_id=? AND kind='feature' AND object_id=?", [$freeze['id'],$scope])->fetchColumn(), 'unknown feature scope');
    }
    $requestDigest = digest((object)['basis'=>$basis,'stage'=>$r->stage,'scope'=>$scope,'target'=>$target,'decision'=>$r->decision,'reviewer'=>$actor,'evidence'=>$evidence,'prior'=>$prior,'upstream'=>$upstream]);
    $history = decisionHistory($db, $freeze);
    $retryId = query($db, 'SELECT id FROM zt_spectrace_decision WHERE freeze_id=? AND request_digest=?', [$freeze['id'],$requestDigest])->fetchColumn();
    $last = null;
    $currentEpic = null;
    foreach ($history as $entry) {
        if ($entry['id'] === (int)$retryId) return $entry;
        if ($entry['stage'] === $r->stage && $entry['scope'] === $scope) $last = $entry['id'];
        if ($entry['stage'] === 'epic' && $entry['current'] && $entry['decision'] === 'accepted') $currentEpic = $entry['id'];
    }
    demand($last === $prior, 'prior decision conflict');
    demand($r->stage !== 'spec' || $upstream === $currentEpic, 'upstream epic decision no longer valid');
    $head = (int)query($db, 'SELECT MAX(revision) FROM zt_spectrace_freeze WHERE product=? AND epic=?', [$r->product,$r->epic])->fetchColumn();
    demand($head === (int)$freeze['revision'], 'cannot review a superseded freeze');
    $submitter = null;
    if ($r->stage === 'spec') {
        $submitter = candidateAuthor($db, $freeze, $r);
        demand($actor !== $submitter, 'spec author cannot self-review');
    }
    $report = mechanicalReport($r, $submitter);
    query($db, 'INSERT INTO zt_spectrace_decision (freeze_id,stage,scope,decision,target_revision,reviewer,evidence,mechanical_report,prior_decision,epic_decision,request_digest) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$freeze['id'],$r->stage,$scope,$r->decision,$target,$actor,canonical($evidence),$report === null ? null : canonical($report),$prior,$upstream,$requestDigest]);
    $rows = decisionHistory($db, $freeze);
    return end($rows);
}
