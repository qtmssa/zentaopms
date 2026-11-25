<?php
declare(strict_types=1);

/* A local integration entry point. Authentication and product grants are supplied by
 * the invoking service through its protected environment. Never expose it as HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/common.php';
require __DIR__ . '/graph.php';
require __DIR__ . '/decisions.php';
require __DIR__ . '/candidates.php';
require __DIR__ . '/projection.php';

try {
    $action = $argv[1] ?? '';
    $roles = ['install'=>['installer'],'import'=>['author'],'submit'=>['author'],'snapshot'=>['author','reviewer','reader'],'decision'=>['reviewer']];
    $readers = ['reader','author','reviewer','publisher'];
    $roles += ['publish'=>['publisher'], 'projection'=>$readers, 'projections'=>$readers];
    $roles['snapshot'][] = 'publisher';
    $roles['structure'] = ['author'];
    $readOnly = in_array($action, ['snapshot','projection','projections'], true);
    demand(count($argv) === 2 && isset($roles[$action]), 'unknown CLI action');
    demand(in_array(envRequired('TRACE_ROLE'), $roles[$action], true), 'action not granted');
    $db = new PDO(envRequired('TRACE_DSN'), envRequired('TRACE_DB_USER'), getenv('TRACE_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $db->exec("SET time_zone='+00:00'");
    if ($action === 'install') {
        demand($db->query("SHOW TABLES LIKE 'zt_product'")->fetchColumn() === 'zt_product', 'need a ZenTao database with zt_ prefix');
        $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
        $answer = ['installed'=>true];
    } else {
        $r = json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);
        demand($r instanceof stdClass, 'JSON object required');
        $product = positive($r->product ?? null, 'product');
        demand((string)$product === envRequired('TRACE_PRODUCT'), 'product not granted');
        $epic = label($r->epic ?? null, 'epic', 128);
        $actor = label(envRequired('TRACE_ACTOR'), 'actor', 128);
        if (!$readOnly) $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        else $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        demand((bool)query($db, "SELECT 1 FROM zt_product WHERE id=? AND deleted='0'", [$product])->fetchColumn(), 'missing or deleted product');
        demand((bool)query($db, "SELECT 1 FROM zt_user WHERE BINARY account=? AND deleted='0'", [$actor])->fetchColumn(), 'missing or deleted actor');
        if (!$readOnly) {
            // Serialize each Epic independently, including the initial import.
            query($db, 'INSERT INTO zt_spectrace_stream(product,epic) VALUES(?,?) ON DUPLICATE KEY UPDATE product=VALUES(product)', [$product,$epic]);
            query($db, 'SELECT product FROM zt_spectrace_stream WHERE product=? AND epic=? FOR UPDATE', [$product,$epic])->fetch();
        }
        if ($action === 'import') {
            $answer = storeGraph($db, $r, $product, $epic, $actor);
        } else {
            if (!$readOnly) positive($r->revision ?? null, 'revision');
            $freeze = fullRow($db, $product, $epic, $r->revision ?? null);
            if ($action === 'publish') {
                $answer = publishProjection($db, $r, $freeze, $actor);
            } elseif ($action === 'projection' || $action === 'projections') {
                $answer = readProjection($db, $r, $freeze, $action === 'projections');
            } elseif ($action === 'submit') {
                $answer = submitCandidate($db, $r, $freeze, $actor);
            } elseif ($action === 'decision' || $action === 'structure') {
                $answer = appendDecision($db, $r, $freeze, $actor, $action === 'structure');
            } else {
                $answer = receipt($freeze) + ['active_revision'=>activeRevision($db,$freeze),'author'=>$freeze['author'],'graph'=>json_decode($freeze['graph'], false, 512, JSON_THROW_ON_ERROR),
                                              'statistics'=>statistics($db,(int)$freeze['id']),'decisions'=>decisionHistory($db,$freeze)];
            }
        }
        $db->commit();
    }
    echo json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // DB errors may contain connection details or parts of the request.
    $error = $e instanceof DomainException || $e instanceof JsonException ? $e->getMessage() : 'database or runtime error';
    fwrite(STDERR, json_encode(['error'=>$error], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
