<?php
declare(strict_types=1);

function recordObject(PDO $db, int $product, string $epic, string $kind, object $obj): void
{
    $identity = [$product, $epic, $kind, $obj->id, version($obj->revision ?? null)];
    $fingerprint = digest($obj);
    query($db, 'INSERT INTO zt_spectrace_object (product,epic,kind,object_id,version,digest) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE digest=digest', array_merge($identity, [$fingerprint]));
    $old = query($db, 'SELECT digest FROM zt_spectrace_object WHERE product=? AND epic=? AND kind=? AND object_id=? AND version=?', $identity)->fetchColumn();
    demand($old === $fingerprint, "changed $kind must have a new revision");
}

function storeGraph(PDO $db, object $request, int $product, string $epic, string $actor): array
{
    $graph = $request->graph ?? null;
    demand($graph instanceof stdClass, 'missing graph');
    gitId($graph->baseline ?? null, 'baseline');
    $features = indexed($graph->features ?? null, 'features');
    $sources = indexed($graph->sources ?? null, 'sources');
    $items = indexed($graph->items ?? null, 'items');
    $expected = $request->expected_revision ?? null;
    demand(is_int($expected) && $expected >= 0, 'expected_revision required');
    $encoded = canonical($graph);
    $basis = hash('sha256', $encoded);
    $head = (int)query($db, 'SELECT MAX(revision) FROM zt_spectrace_freeze WHERE product=? AND epic=?', [$product, $epic])->fetchColumn();
    if ($head !== $expected) {
        $retry = query($db, 'SELECT * FROM zt_spectrace_freeze WHERE product=? AND epic=? AND revision=? AND basis=? AND author=?', [$product, $epic, $expected + 1, $basis, $actor])->fetch();
        demand((bool)$retry, 'revision conflict');
        return receipt($retry);
    }
    $revision = $expected + 1;
    query($db, 'INSERT INTO zt_spectrace_freeze(product,epic,revision,basis,author,graph) VALUES(?,?,?,?,?,?)', [$product,$epic,$revision,$basis,$actor,$encoded]);
    $freeze = (int)$db->lastInsertId();
    foreach (['feature' => $features, 'source' => $sources, 'item' => $items] as $kind => $entries) {
        foreach ($entries as $obj) {
            if ($kind !== 'feature') recordObject($db, $product, $epic, $kind, $obj);
            query($db, 'INSERT INTO zt_spectrace_member(freeze_id,kind,object_id,version) VALUES(?,?,?,?)', [$freeze,$kind,$obj->id,$kind === 'feature' ? '' : version($obj->revision)]);
        }
    }
    foreach ($sources as $source) {
        label($source->path ?? null, 'source.path', 4096);
        gitId($source->blob ?? null, 'source.blob');
    }
    foreach ($items as $item) {
        demand(is_string($item->summary ?? null) && trim($item->summary) !== '', 'missing item.summary');
        $seen = [];
        foreach (objects($item->sources ?? null, 'item.sources') as $edge) {
            $id = label($edge->id ?? null, 'source.id');
            $sourceVersion = version($edge->revision ?? null);
            $path = label($edge->path ?? null, 'source.path', 4096);
            $blob = gitId($edge->blob ?? null, 'locator.blob');
            $source = $sources[$id] ?? null;
            $matches = $source && version($source->revision) === $sourceVersion && $source->path === $path && $source->blob === $blob;
            $key = digest($edge);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            query($db, 'INSERT INTO zt_spectrace_locator(freeze_id,item_id,source_id,source_version,locator_digest,locator,matches_source) VALUES(?,?,?,?,?,?,?)', [$freeze,$item->id,$id,$sourceVersion,$key,canonical($edge),(int)$matches]);
        }
        $seen = [];
        foreach (objects($item->actual_consumers ?? null, 'actual_consumers') as $edge) {
            $feature = label($edge->feature_id ?? null, 'feature_id');
            $obligation = label($edge->obligation_id ?? null, 'obligation_id');
            $key = $feature . "\0" . $obligation;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            query($db, 'INSERT INTO zt_spectrace_consumer(freeze_id,item_id,feature_id,obligation_id,matches_feature) VALUES(?,?,?,?,?)', [$freeze,$item->id,$feature,$obligation,(int)isset($features[$feature])]);
        }
    }
    return compact('product','epic','revision','basis');
}

function statistics(PDO $db, int $freeze): array
{
    $result = ['sources'=>0,'items'=>0,'features'=>0];
    foreach (query($db, 'SELECT kind,COUNT(*) AS n FROM zt_spectrace_member WHERE freeze_id=? GROUP BY kind', [$freeze])->fetchAll() as $row) {
        $result[$row['kind'] . 's'] = (int)$row['n'];
    }
    $locator = query($db, 'SELECT COUNT(*),COALESCE(SUM(matches_source=0),0) FROM zt_spectrace_locator WHERE freeze_id=?', [$freeze])->fetch(PDO::FETCH_NUM);
    $consumer = query($db, 'SELECT COUNT(*),COALESCE(SUM(matches_feature=0),0) FROM zt_spectrace_consumer WHERE freeze_id=?', [$freeze])->fetch(PDO::FETCH_NUM);
    return $result + ['locator_edges'=>(int)$locator[0],'unmatched_source_versions'=>(int)$locator[1],'consumer_edges'=>(int)$consumer[0],'unknown_consumers'=>(int)$consumer[1]];
}
