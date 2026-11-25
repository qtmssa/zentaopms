<?php
declare(strict_types=1);

function submitCandidate(PDO $db, object $r, array $freeze, string $actor): array
{
    demand(digestId($r->basis ?? null, 'basis') === $freeze['basis'], 'frozen basis mismatch');
    $scope = label($r->scope ?? null, 'scope');
    $target = digestId($r->target_revision ?? null, 'spec target_revision');
    $epicDecision = positive($r->epic_decision ?? null, 'epic_decision');
    demand((bool)query($db, "SELECT 1 FROM zt_spectrace_member WHERE freeze_id=? AND kind='feature' AND object_id=?", [$freeze['id'],$scope])->fetchColumn(), 'unknown feature scope');
    $head = (int)query($db, 'SELECT MAX(revision) FROM zt_spectrace_freeze WHERE product=? AND epic=?', [$r->product,$r->epic])->fetchColumn();
    demand($head === (int)$freeze['revision'], 'cannot submit to a superseded freeze');
    $upstream = query($db, "SELECT id,decision FROM zt_spectrace_decision WHERE freeze_id=? AND stage='epic' ORDER BY id DESC LIMIT 1", [$freeze['id']])->fetch();
    demand($upstream && (int)$upstream['id'] === $epicDecision && $upstream['decision'] === 'accepted', 'upstream epic decision no longer valid');
    // A byte-identical target keeps its authenticated submitter across first-gate decisions.
    $owner = query($db, 'SELECT author FROM zt_spectrace_candidate WHERE freeze_id=? AND scope=? AND target_revision=? LIMIT 1', [$freeze['id'],$scope,$target])->fetchColumn();
    demand($owner === false || $owner === $actor, 'candidate already belongs to another author');
    query($db, 'INSERT INTO zt_spectrace_candidate (freeze_id,scope,target_revision,epic_decision,author) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE author=author', [$freeze['id'],$scope,$target,$epicDecision,$actor]);
    return receipt($freeze) + ['scope'=>$scope,'target_revision'=>$target,'epic_decision'=>$epicDecision,'author'=>$actor];
}

function candidateAuthor(PDO $db, array $freeze, object $r): string
{
    $author = query($db, 'SELECT author FROM zt_spectrace_candidate WHERE freeze_id=? AND scope=? AND target_revision=? AND epic_decision=?', [$freeze['id'],$r->scope,$r->target_revision,$r->epic_decision])->fetchColumn();
    demand($author !== false, 'exact candidate must be submitted by its author');
    return $author;
}
