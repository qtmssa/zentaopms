<?php
declare(strict_types=1);

// A content-addressed read model. Gate decisions are written exclusively by the
// decision workflow. Only the service which reads pinned Git trees can publish.
function projectionPath($value): string
{
    $path = label($value, 'Git path', 4096);
    demand(strpos($path, '\\') === false, 'invalid Git path');
    foreach (explode('/', $path) as $component) {
        demand($component !== '' && $component !== '.' && $component !== '..', 'invalid Git path');
    }
    return $path;
}

function projectionText($value, string $blob): string
{
    demand(is_string($value) && strpos($value, "\0") === false, 'expected UTF-8 text');
    $algorithm = strlen($blob) === 40 ? 'sha1' : 'sha256';
    $actual = hash($algorithm, 'blob ' . strlen($value) . "\0" . $value);
    demand(hash_equals($blob, $actual), 'Git blob does not match text bytes');
    return $value;
}

function projectionQuote(object $row, string $content, int $length): void
{
    $start = positive($row->start ?? null, 'start');
    $end = positive($row->end ?? null, 'end');
    demand($start <= $end && $end <= $length, 'text range outside blob');
    demand(is_string($row->quote ?? null) && $row->quote === mb_substr($content, $start - 1, $end - $start + 1, 'UTF-8'), 'quote differs from exact text range');
}

function projectionKey(array $values): string
{
    return canonical($values);
}

function projectionObligationKey(object $edge): string
{
    return projectionKey([
        label($edge->item_id ?? null, 'item_id'),
        version($edge->item_revision ?? null),
        label($edge->obligation_id ?? null, 'obligation_id'),
        label($edge->feature_id ?? null, 'feature_id')
    ]);
}

function projectionUnique(array &$index, string $key, $value, string $what): void
{
    demand(!array_key_exists($key, $index), "duplicate $what");
    $index[$key] = $value;
}

function projectionDocuments(object $p): array
{
    $directories = [];
    foreach (objects($p->directories ?? null, 'directories') as $dir) {
        $path = projectionPath($dir->path ?? null);
        demand(($dir->id ?? null) === $path && property_exists($dir, 'parent_id'), 'incorrect directory identity');
        projectionUnique($directories, $path, $dir, 'directory');
    }
    foreach ($directories as $dir) {
        $parent = dirname($dir->path);
        demand($dir->parent_id === ($parent === '.' ? null : $parent)
            && ($parent === '.' || isset($directories[$parent])), 'incorrect directory parent');
    }
    $documents = [];
    foreach (objects($p->documents ?? null, 'documents') as $doc) {
        $path = projectionPath($doc->path ?? null);
        $parent = dirname($path);
        demand(property_exists($doc, 'directory_id') && $doc->directory_id === ($parent === '.' ? null : $parent)
            && ($parent === '.' || isset($directories[$parent])) && !isset($directories[$path]), 'incorrect document directory');
        demand(property_exists($doc, 'text') && in_array($doc->state ?? null, ['readable','unreadable','unextractable'], true), 'invalid document state');
        demand(property_exists($doc, 'blob'), 'missing document blob');
        $blob = $doc->blob === null && $doc->state === 'unreadable' ? null : gitId($doc->blob, 'document blob');
        $segments = objects($doc->segments ?? null, 'segments');
        if ($doc->state !== 'readable') {
            demand($doc->text === null && !$segments, 'unavailable text must have no partition');
            $length = 0;
        } else {
            $text = projectionText($doc->text, $blob);
            $length = mb_strlen($text, 'UTF-8');
            $next = 1;
            foreach ($segments as $seg) {
                demand(($seg->start ?? null) === $next && is_int($seg->end ?? null)
                    && $seg->end >= $next && $seg->end <= $length, 'partition contains gap, overlap or invalid bounds');
                demand(in_array($seg->state ?? null, ['linked','disposed','unlinked','uncut'], true), 'unknown segment state');
                demand(is_array($seg->fragment_ids ?? null), 'missing segment fragment IDs');
                $next = $seg->end + 1;
            }
            demand($next === $length + 1, 'partition does not cover document');
        }
        projectionUnique($documents, $path, [$doc,$length], 'document');
    }
    return $documents;
}

function validateProjection(object $p, object $graph): void
{
    demand(($p->schema ?? null) === 1 && ($p->baseline ?? null) === $graph->baseline, 'projection schema or frozen baseline mismatch');
    $docs = projectionDocuments($p);
    $fragments = indexed($p->fragments ?? null, 'fragments');
    foreach ($fragments as $fragment) {
        $path = projectionPath($fragment->path ?? null);
        demand(isset($docs[$path]), 'unknown fragment document');
        [$doc,$length] = $docs[$path];
        demand($doc->state === 'readable' && ($fragment->blob ?? null) === $doc->blob, 'fragment source version mismatch');
        projectionQuote($fragment, $doc->text, $length);
    }
    $covered = [];
    foreach ($docs as [$doc,$length]) {
        foreach ($doc->segments as $seg) {
            $seen = [];
            foreach ($seg->fragment_ids as $id) {
                $id = label($id, 'segment fragment ID');
                $fragment = $fragments[$id] ?? null;
                demand($fragment && !isset($seen[$id]) && $fragment->path === $doc->path
                    && $seg->start >= $fragment->start && $seg->end <= $fragment->end, 'incorrect segment fragment');
                $seen[$id] = true;
                $covered[$id] = ($covered[$id] ?? 0) + $seg->end - $seg->start + 1;
            }
        }
    }
    foreach ($fragments as $id=>$fragment) {
        demand(($covered[$id] ?? 0) === $fragment->end - $fragment->start + 1, 'fragment missing from partition');
    }
    $features = indexed($p->features ?? null, 'features');
    $frozenFeatures = indexed($graph->features, 'frozen features');
    demand(!array_diff_key($features,$frozenFeatures) && !array_diff_key($frozenFeatures,$features), 'features differ from frozen graph');
    $obligations = [];
    foreach (objects($p->obligations ?? null, 'obligations') as $row) {
        $key = projectionObligationKey($row);
        // Keep identified but unresolved consumers visible as gaps.
        demand(isset($features[$row->feature_id]) || ($row->state ?? null) === 'unassigned', 'unknown obligation feature');
        projectionUnique($obligations, $key, true, 'obligation');
    }
    $linkedFragments = [];
    $seen = [];
    foreach (objects($p->fragment_obligations ?? null, 'fragment_obligations') as $edge) {
        $id = label($edge->fragment_id ?? null, 'fragment_id');
        $key = projectionObligationKey($edge);
        demand(isset($fragments[$id],$obligations[$key],$features[$edge->feature_id]), 'unknown fragment or obligation');
        projectionUnique($seen, projectionKey([$id,$key]), true, 'fragment obligation edge');
        $linkedFragments[$id] = true;
    }
    foreach ($docs as [$doc,$length]) {
        foreach ($doc->segments as $seg) {
            if ($seg->state === 'uncut') demand(!$seg->fragment_ids, 'uncut interval contains fragment');
            if ($seg->state !== 'linked') continue;
            $required = false;
            foreach ($seg->fragment_ids as $id) {
                if (($fragments[$id]->disposition ?? 'required') !== 'required') continue;
                demand(isset($linkedFragments[$id]), 'linked interval has disconnected fragment');
                $required = true;
            }
            demand($required, 'linked interval has no required fragment');
        }
    }
    $versions = [];
    $targets = [];
    foreach (objects($p->specs ?? null, 'specs') as $spec) {
        $feature = label($spec->feature_id ?? null, 'spec feature');
        demand(isset($features[$feature]), 'unknown spec feature');
        $path = projectionPath($spec->path ?? null);
        $registered = $frozenFeatures[$feature]->spec_path ?? null;
        demand($registered === null || $registered === $path, 'spec path differs from registered feature');
        gitId($spec->commit ?? null, 'spec commit');
        $blob = gitId($spec->blob ?? null, 'spec blob');
        $text = projectionText($spec->text ?? null, $blob);
        $length = mb_strlen($text, 'UTF-8');
        // The projection keeps a single candidate per feature. Older candidates
        // are retrievable under their immutable publication revision.
        projectionUnique($versions, $feature, true, 'feature candidate');
        foreach (objects($spec->targets ?? null, 'targets') as $target) {
            $kind = label($target->kind ?? null, 'target kind');
            $id = label($target->id ?? null, 'target ID');
            projectionQuote($target, $text, $length);
            projectionUnique($targets, projectionKey([$feature,$blob,$kind,$id]), true, 'target');
        }
    }
    $seen = [];
    foreach (objects($p->target_obligations ?? null, 'target_obligations') as $edge) {
        $key = projectionObligationKey($edge);
        $target = projectionKey([$edge->feature_id,gitId($edge->blob ?? null, 'target blob'),label($edge->kind ?? null,'target kind'),label($edge->target_id ?? null,'target ID')]);
        demand(isset($obligations[$key],$targets[$target]), 'unknown target or obligation');
        projectionUnique($seen, projectionKey([$key,$target]), true, 'target obligation edge');
    }
}

function projectionReceipt(array $freeze, array $row): array
{
    return receipt($freeze) + [
        'projection_revision'=>(int)$row['projection_revision'],
        'projection_digest'=>$row['digest'], 'publisher'=>$row['publisher'],
        'created_at'=>$row['created_at']
    ];
}

function publishProjection(PDO $db, object $r, array $freeze, string $actor): array
{
    demand(digestId($r->basis ?? null, 'basis') === $freeze['basis'], 'frozen basis mismatch');
    $expected = $r->expected_projection ?? null;
    demand(is_int($expected) && $expected >= 0, 'expected_projection required');
    $projection = $r->projection ?? null;
    demand($projection instanceof stdClass, 'missing projection');
    demand(!property_exists($projection, 'basis') || $projection->basis === $freeze['basis'], 'projection basis mismatch');
    $payload = canonical($projection);
    $digest = hash('sha256',$payload);
    $columns = 'projection_revision,digest,publisher,created_at';
    $latest = query($db, "SELECT $columns FROM zt_spectrace_projection WHERE freeze_id=? ORDER BY projection_revision DESC LIMIT 1", [$freeze['id']])->fetch();
    $head = $latest ? (int)$latest['projection_revision'] : 0;
    if ($expected !== $head) {
        $retry = query($db, "SELECT $columns FROM zt_spectrace_projection WHERE freeze_id=? AND projection_revision=? AND digest=? AND publisher=?", [$freeze['id'],$expected + 1,$digest,$actor])->fetch();
        demand((bool)$retry, 'projection revision conflict');
        return projectionReceipt($freeze,$retry);
    }
    demand(activeRevision($db,$freeze) === (int)$freeze['revision'], 'cannot publish to a superseded freeze');
    if ($latest && $latest['digest'] === $digest) return projectionReceipt($freeze,$latest);
    validateProjection($projection, json_decode($freeze['graph'], false, 512, JSON_THROW_ON_ERROR));
    query($db, 'INSERT INTO zt_spectrace_projection(freeze_id,projection_revision,digest,payload,publisher) VALUES(?,?,?,?,?)',
          [$freeze['id'],$head + 1,$digest,$payload,$actor]);
    $row = query($db, "SELECT $columns FROM zt_spectrace_projection WHERE freeze_id=? AND projection_revision=?", [$freeze['id'],$head + 1])->fetch();
    return projectionReceipt($freeze,$row);
}

function readProjection(PDO $db, object $r, array $freeze, bool $list): array
{
    $base = receipt($freeze) + ['active_revision'=>activeRevision($db,$freeze)];
    $params = [$freeze['id']];
    if ($list) {
        $rows = query($db, 'SELECT projection_revision,digest,publisher,created_at FROM zt_spectrace_projection WHERE freeze_id=? ORDER BY projection_revision DESC',$params)->fetchAll();
        return $base + ['projections'=>array_map(static function ($row) use ($freeze) { return projectionReceipt($freeze,$row); }, $rows)];
    }
    $filter = '';
    if (isset($r->projection_revision)) {
        $params[] = positive($r->projection_revision, 'projection_revision');
        $filter = ' AND projection_revision=?';
    }
    $row = query($db, "SELECT projection_revision,digest,publisher,created_at,payload FROM zt_spectrace_projection WHERE freeze_id=?$filter ORDER BY projection_revision DESC LIMIT 1",$params)->fetch();
    if (!$row) {
        demand($filter === '', 'unknown projection revision');
        return $base + ['projection_revision'=>0,'projection_digest'=>null,'projection'=>null];
    }
    demand(hash_equals($row['digest'], hash('sha256',$row['payload'])), 'stored projection digest mismatch');
    return $base + projectionReceipt($freeze,$row) + ['projection'=>json_decode($row['payload'], false, 512, JSON_THROW_ON_ERROR)];
}
