<?php
declare(strict_types=1);

function demand(bool $ok, string $message): void
{
    if (!$ok) throw new DomainException($message);
}

function label($value, string $field, int $limit = 255): string
{
    demand(is_string($value) && trim($value) !== '' && strlen($value) <= $limit && !preg_match('/[\x00-\x1f\x7f]/', $value), "invalid $field");
    return $value;
}

function positive($value, string $field): int
{
    demand(is_int($value) && $value > 0, "invalid $field");
    return $value;
}

function version($value): string
{
    return is_int($value) ? (string)positive($value, 'version') : label($value, 'version', 128);
}

function gitId($value, string $field): string
{
    demand(is_string($value) && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $value) === 1, "invalid $field");
    return $value;
}

function digestId($value, string $field): string
{
    demand(is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1, "invalid $field");
    return $value;
}

function sortedValue($value)
{
    if (is_object($value)) {
        $fields = get_object_vars($value);
        ksort($fields, SORT_STRING);
        $result = new stdClass();
        foreach ($fields as $key => $child) $result->{$key} = sortedValue($child);
        return $result;
    }
    if (is_array($value)) return array_map('sortedValue', $value);
    demand(!is_float($value), 'use integers or strings for exact JSON values');
    return $value;
}

function canonical($value): string
{
    return json_encode(sortedValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
}

function digest($value): string
{
    return hash('sha256', canonical($value));
}

function envRequired(string $name): string
{
    $value = getenv($name);
    demand($value !== false && $value !== '', "missing $name");
    return $value;
}

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    static $cache = [];
    $stmt = $cache[$sql] ?? ($cache[$sql] = $db->prepare($sql));
    $stmt->closeCursor();
    $stmt->execute($params);
    return $stmt;
}

function objects($value, string $field): array
{
    demand(is_array($value), "invalid $field list");
    foreach ($value as $row) demand($row instanceof stdClass, "invalid $field entry");
    return $value;
}

function indexed($rows, string $field): array
{
    $index = [];
    foreach (objects($rows, $field) as $row) {
        $id = label($row->id ?? null, "$field.id");
        demand(!isset($index[$id]), "duplicate $field.id");
        $index[$id] = $row;
    }
    return $index;
}

function fullRow(PDO $db, int $product, string $epic, $revision): array
{
    $params = [$product, $epic];
    $filter = '';
    if ($revision !== null) {
        $filter = ' AND revision=?';
        $params[] = positive($revision, 'revision');
    }
    $row = query($db, "SELECT * FROM zt_spectrace_freeze WHERE product=? AND epic=?$filter ORDER BY revision DESC LIMIT 1", $params)->fetch();
    demand((bool)$row, 'unknown frozen revision');
    return $row;
}

function receipt(array $row): array
{
    return ['product' => (int)$row['product'], 'epic' => $row['epic'], 'revision' => (int)$row['revision'], 'basis' => $row['basis']];
}


function activeRevision(PDO $db, array $freeze): int
{
    return (int)query($db, 'SELECT MAX(revision) FROM zt_spectrace_freeze WHERE product=? AND epic=?', [$freeze['product'],$freeze['epic']])->fetchColumn();
}
