<?php
declare(strict_types=1);

/**
 * Read the immutable trace projection after the controller checks ZenTao access.
 * Document and spec bodies come from the published projection of their Git blobs.
 */
final class productTraceWorkbench
{
    public function __construct(private dbh $db)
    {
    }

    /** @return array<string, array<int, array>> */
    public function versions(int $productID): array
    {
        $rows = $this->read(
            'SELECT f.epic, f.revision, p.projection_revision
             FROM zt_spectrace_freeze f
             LEFT JOIN zt_spectrace_projection p ON p.freeze_id = f.id
             WHERE f.product = ?
             ORDER BY f.epic, f.revision DESC, p.projection_revision DESC',
            array($productID)
        );

        $versions = array();
        foreach($rows as $row)
        {
            $epicKey  = bin2hex($row['epic']);
            $revision = (int)$row['revision'];
            $versions[$epicKey][$revision] ??= array();
            if($row['projection_revision'] !== null) $versions[$epicKey][$revision][] = (int)$row['projection_revision'];
        }
        return $versions;
    }

    public function projection(int $productID, string $epic, int $revision, int $projectionRevision): array
    {
        $rows = $this->read(
            'SELECT f.basis, p.digest, p.payload, p.created_at
             FROM zt_spectrace_freeze f
             INNER JOIN zt_spectrace_projection p ON p.freeze_id = f.id
             WHERE f.product = ? AND f.epic = ? AND f.revision = ? AND p.projection_revision = ?',
            array($productID, $epic, $revision, $projectionRevision)
        );
        if(!$rows) return array();

        $projection = $rows[0];
        $payload = json_decode($projection['payload'], true, 512, JSON_THROW_ON_ERROR);
        if(($payload['schema'] ?? null) !== 1) throw new UnexpectedValueException('Unsupported trace projection schema');
        $projection['payload'] = $payload;
        return $projection;
    }

    public function decisions(int $productID, string $epic, int $revision, string $stage, string $scope, string $target): array
    {
        if($target === '') return array();

        return $this->read(
            'SELECT d.id, d.decision, d.reviewer, d.created_at, d.evidence, d.target_revision, d.epic_decision,
                    CASE WHEN d.stage = "epic"
                      AND JSON_UNQUOTE(JSON_EXTRACT(f.graph, "$.audit_mode")) = "workbench"
                      AND JSON_UNQUOTE(JSON_EXTRACT(d.mechanical_report, "$.decision_kind")) = "machine_structure"
                      THEN "machine_structure" ELSE "independent_review" END AS decision_kind,
                    CASE WHEN NOT EXISTS(
                        SELECT 1 FROM zt_spectrace_freeze newer
                        WHERE newer.product = f.product AND newer.epic = f.epic AND newer.revision > f.revision
                    ) AND NOT EXISTS(
                        SELECT 1 FROM zt_spectrace_decision successor
                        WHERE successor.freeze_id = f.id AND successor.stage = d.stage AND successor.scope = d.scope AND successor.id > d.id
                    ) AND (d.stage = "epic" OR d.epic_decision = (
                        SELECT CASE WHEN parent.decision = "accepted" THEN parent.id ELSE NULL END
                        FROM zt_spectrace_decision parent
                        WHERE parent.freeze_id = f.id AND parent.stage = "epic" AND parent.scope = f.epic
                        ORDER BY parent.id DESC LIMIT 1
                    )) THEN 1 ELSE 0 END AS is_current
             FROM zt_spectrace_freeze f
             INNER JOIN zt_spectrace_decision d ON d.freeze_id = f.id
             WHERE f.product = ? AND f.epic = ? AND f.revision = ? AND d.stage = ? AND d.scope = ? AND d.target_revision = ?
             ORDER BY d.id DESC',
            array($productID, $epic, $revision, $stage, $scope, $target)
        );
    }

    private function read(string $sql, array $params): array
    {
        $query = $this->db->prepare($sql);
        if(!$query || !$query->execute($params)) throw new RuntimeException('Trace projection query failed');
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class productTraceNavigation
{
    public const TYPES = array(
        'document' => 'documents', 'fragment' => 'fragments', 'feature' => 'features',
        'obligation' => 'obligations', 'spec' => 'specs', 'target' => 'targets'
    );

    private array $entries = array();
    private array $byDocument = array();
    private array $byFragment = array();
    private array $byFeature = array();
    private array $byObligation = array();
    private array $byTarget = array();

    public function __construct(array $payload)
    {
        foreach(self::TYPES as $kind => $field)
        {
            $this->entries[$kind] = $kind === 'target' ? array() : array_values($payload[$field] ?? array());
        }

        foreach($this->entries['document'] as $i => $row) $this->byDocument[$row['path']][$row['blob']] = $i;
        foreach($this->entries['fragment'] as $i => $row) $this->byFragment[$row['id']] = $i;
        foreach($this->entries['feature']  as $i => $row) $this->byFeature[$row['id']] = $i;
        foreach($this->entries['obligation'] as $i => $row) $this->byObligation[$this->obligationKey($row)] = $i;

        foreach($this->entries['spec'] as $specIndex => $spec)
        {
            foreach($spec['targets'] as $row)
            {
                $index = count($this->entries['target']);
                $this->entries['target'][] = $row + array('specIndex' => $specIndex, 'feature_id' => $spec['feature_id'], 'blob' => $spec['blob']);
                $this->byTarget[$spec['feature_id']][$spec['blob']][$row['kind']][$row['id']] = $index;
            }
        }

        foreach($this->entries['fragment'] as $i => $fragment)
        {
            $document = $this->byDocument[$fragment['path']][$fragment['blob']] ?? null;
            $this->entries['fragment'][$i]['documentIndex'] = $document;
            if($document !== null) $this->entries['document'][$document]['fragments'][] = $i;
        }
        foreach($this->entries['obligation'] as $i => $obligation)
        {
            $feature = $this->byFeature[$obligation['feature_id']] ?? null;
            $this->entries['obligation'][$i]['featureIndex'] = $feature;
            if($feature !== null) $this->entries['feature'][$feature]['obligations'][] = $i;
        }
        foreach($this->entries['spec'] as $i => $spec)
        {
            $feature = $this->byFeature[$spec['feature_id']] ?? null;
            $this->entries['spec'][$i]['featureIndex'] = $feature;
            if($feature !== null) $this->entries['feature'][$feature]['specs'][] = $i;
        }
        foreach($this->entries['target'] as $i => $target)
        {
            $this->entries['spec'][$target['specIndex']]['targetsIndex'][] = $i;
        }

        foreach($payload['fragment_obligations'] ?? array() as $edge)
        {
            $fragment   = $this->byFragment[$edge['fragment_id']] ?? null;
            $obligation = $this->byObligation[$this->obligationKey($edge)] ?? null;
            if($fragment === null || $obligation === null) continue;
            $this->entries['fragment'][$fragment]['obligations'][] = $obligation;
            $this->entries['obligation'][$obligation]['fragments'][] = $fragment;
        }
        foreach($payload['target_obligations'] ?? array() as $edge)
        {
            $target = $this->byTarget[$edge['feature_id']][$edge['blob']][$edge['kind']][$edge['target_id']] ?? null;
            $obligation = $this->byObligation[$this->obligationKey($edge)] ?? null;
            if($target === null || $obligation === null) continue;
            $this->entries['target'][$target]['obligations'][] = $obligation;
            $this->entries['obligation'][$obligation]['targets'][] = $target;
        }
    }

    public function list(string $kind): array
    {
        return $this->entries[$kind];
    }

    public function entry(string $kind, int $number): ?array
    {
        return $this->entries[$kind][$number - 1] ?? null;
    }

    public function fragmentIndex(string $id): ?int
    {
        return $this->byFragment[$id] ?? null;
    }

    /** Index all material under its complete, versioned identity. */
    private function obligationKey(array $row): string
    {
        return json_encode(array($row['item_id'], (string)$row['item_revision'], $row['feature_id'], $row['obligation_id']), JSON_THROW_ON_ERROR);
    }
}
