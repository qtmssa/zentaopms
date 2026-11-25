<?php
declare(strict_types=1);
require dirname(__DIR__) . '/common.php';

$env = array_merge(getenv(), [
    'TRACE_DSN'=>'mysql:unix_socket=/data/mysql/tmp/mysql.sock;dbname=zentao;charset=utf8mb4',
    'TRACE_DB_USER'=>'root', 'TRACE_DB_PASSWORD'=>'', 'TRACE_PRODUCT'=>'1', 'TRACE_ACTOR'=>'productManager'
]);
function launch(string $action, array $body, array $overrides): array
{
    global $env;
    $process = proc_open([PHP_BINARY,dirname(__DIR__) . '/cli.php',$action], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, array_merge($env,$overrides));
    fwrite($pipes[0],json_encode((object)$body, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    return [$process,$pipes];
}
function finish(array $child): array
{
    [$process,$pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    return [$code,json_decode($code === 0 ? $out : $err, true, 512, JSON_THROW_ON_ERROR)];
}
function runCli(string $action, array $body, array $overrides, ?string $failure = null): array
{
    [$exit,$answer] = finish(launch($action,$body,$overrides));
    if ($failure === null) demand($exit === 0, "$action failed: " . json_encode($answer));
    else demand($exit !== 0 && str_contains($answer['error'], $failure), "$action expected $failure: " . json_encode($answer));
    return $answer;
}
function mark(string $test): void { echo "PASS $test\n"; }

$installer = ['TRACE_ROLE'=>'installer'];
$writer = ['TRACE_ROLE'=>'author'];
$reader = ['TRACE_ROLE'=>'reader'];
$reviewer = ['TRACE_ROLE'=>'reviewer','TRACE_ACTOR'=>'projectManager'];
$firstInstall = launch('install', [], $installer);
$secondInstall = launch('install', [], $installer);
foreach ([$firstInstall, $secondInstall] as $child) {
    [$exit, $result] = finish($child);
    demand($exit === 0 && $result === ['installed'=>true], 'concurrent trace installation failed');
}
$db = new PDO($env['TRACE_DSN'], $env['TRACE_DB_USER'], $env['TRACE_DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$adminGroup = (int)$db->query("SELECT id FROM zt_group WHERE vision='rnd' AND role='admin' AND project=0 AND devopsSpace=0")->fetchColumn();
$grants = "SELECT `group` FROM zt_grouppriv WHERE module='product' AND method='traceworkbench'";
demand($adminGroup > 0 && $db->query($grants)->fetchAll(PDO::FETCH_COLUMN) === [$adminGroup], 'wrong default trace grants');
$db->exec("DELETE FROM zt_grouppriv WHERE module='product' AND method='traceworkbench'");
runCli('install', [], $installer);
demand($db->query($grants)->fetchAll() === [], 'repeat installation restored a withdrawn grant');
mark('concurrent schema install, native admin group only, and retained permission withdrawal');
$base = ['product'=>1,'epic'=>'fixture'];
$commit = str_repeat('c',40); $blob = str_repeat('a',40);
$source = ['id'=>'S1','revision'=>'v1','path'=>'docs/source.md','blob'=>$blob];
$loc = $source + ['start_line'=>1,'end_line'=>1,'quote'=>'值班人'];
$edge = ['feature_id'=>'F1','obligation_id'=>'O1'];
$item = ['id'=>'R1','revision'=>1,'summary'=>'notify','sources'=>[$loc,$loc],'actual_consumers'=>[$edge,$edge]];
$graph = ['baseline'=>$commit,'sources'=>[$source],'features'=>[['id'=>'F1'],['id'=>'F2']],'items'=>[$item]];
$import = $base + ['expected_revision'=>0,'graph'=>$graph];
$receipt = runCli('import',$import,$writer);
demand($receipt === runCli('import',$import,$writer), 'exact retry');
$snapshot = runCli('snapshot',$base,$reader);
demand($snapshot['revision'] === 1 && $snapshot['graph'] == $graph && $snapshot['statistics']['locator_edges'] === 1 && $snapshot['statistics']['consumer_edges'] === 1, 'freeze and distinct edge counts');
mark('transaction, round-trip, deduplicated relations and exact retry');
runCli('snapshot',['product'=>2,'epic'=>'fixture'],$reader,'product not granted');
runCli('snapshot',['product'=>2,'epic'=>'fixture'],array_merge($reader,['TRACE_PRODUCT'=>'2']),'unknown frozen revision');
runCli('snapshot',['product'=>999,'epic'=>'fixture'],array_merge($reader,['TRACE_PRODUCT'=>'999']),'missing or deleted product');
runCli('snapshot',$base,array_merge($reader,['TRACE_ACTOR'=>'missing-user']),'missing or deleted actor');
runCli('import',$import,$reader,'action not granted');
mark('native product and identity boundary');
$evidence = ['record'=>'test-only reviewer observations','set'=>['O1']];
$first = $receipt + ['stage'=>'epic','scope'=>'fixture','target_revision'=>$commit,'decision'=>'accepted','evidence'=>$evidence];
runCli('decision',$first,$writer,'action not granted');
runCli('decision',$first,['TRACE_ROLE'=>'reviewer'],'self-review');
runCli('decision',$first,$reviewer,'trusted mechanical report required');
$reportFile = tempnam(sys_get_temp_dir(),'trace-check-');
$withReport = array_merge($reviewer,['TRACE_MECHANICAL_REPORT'=>$reportFile]);
$report = $receipt + ['schema'=>1,'stage'=>'epic','scope'=>'fixture','target_revision'=>$commit,'epic_decision'=>null,'status'=>'failed','manifest_digest'=>str_repeat('1',64),'review_evidence_digest'=>digest((object)$evidence)];
file_put_contents($reportFile,json_encode($report));
runCli('decision',$first,$withReport,'checks incomplete');
$report['status'] = 'passed';
file_put_contents($reportFile,json_encode($report));
$changed = $first; $changed['evidence'] = 'unrelated';
runCli('decision',$changed,$withReport,'evidence mismatch');
$d1 = runCli('decision',$first,$withReport);
demand($d1['current'] && $d1['id'] === runCli('decision',$first,$withReport)['id'], 'decision retry');
$second = $receipt + ['stage'=>'spec','scope'=>'F1','target_revision'=>str_repeat('3',64),'epic_decision'=>$d1['id'],'decision'=>'accepted','evidence'=>'test-only independent spec observations'];
$submission = $receipt + ['scope'=>'F1','target_revision'=>$second['target_revision'],'epic_decision'=>$d1['id']];
runCli('decision',$second,$withReport,'must be submitted');
runCli('submit',$submission,$reviewer,'action not granted');
$receipt2 = runCli('submit',$submission,$writer);
demand($receipt2 === runCli('submit',$submission,$writer) && $receipt2['author'] === $env['TRACE_ACTOR'], 'authenticated candidate idempotence');
runCli('submit',$submission,array_merge($writer,['TRACE_ACTOR'=>'projectManager']),'another author');
$otherSubmission = array_merge($submission,['target_revision'=>str_repeat('4',64)]);
runCli('submit',$otherSubmission,array_merge($writer,['TRACE_ACTOR'=>'projectManager']));
$own = array_merge($second,['target_revision'=>$otherSubmission['target_revision']]);
runCli('decision',$own,$withReport,'self-review');
runCli('decision',$second,$withReport,'mechanical report mismatch');
$report2 = array_merge($report,['stage'=>'spec','scope'=>'F1','target_revision'=>$second['target_revision'],'epic_decision'=>$d1['id'],'spec_author'=>'projectManager']);
file_put_contents($reportFile,json_encode($report2));
runCli('decision',$second,$withReport,'differs from recorded submitter');
$report2['spec_author'] = 'productManager';
file_put_contents($reportFile,json_encode($report2));
$d2 = runCli('decision',$second,$withReport);
demand($d2['current'] && $d2['epic_decision'] === $d1['id'], 'spec decision upstream');
$revoke = array_merge($first,['decision'=>'changes_requested','evidence'=>'fixture exception uncovered']);
runCli('decision',$revoke,$reviewer,'prior decision conflict');
$revoke['prior_decision'] = $d1['id'];
$d3 = runCli('decision',$revoke,$reviewer);
$history = runCli('snapshot',$base,$reader)['decisions'];
demand(array_column($history,'current') === [false,false,true] && $d3['id'] !== $d1['id'], 'upstream invalidation');
$next = array_merge($second,['evidence'=>'test-only second review','prior_decision'=>$d2['id']]);
runCli('decision',$next,$withReport,'upstream epic decision no longer valid');
mark('role separation, both exact report bindings, append-only history and upstream revocation');

$new = $graph; $new['items'][0]['summary'] = 'changed';
runCli('import',$base+['expected_revision'=>1,'graph'=>$new],$writer,'new revision');
demand(runCli('snapshot',$base,$reader)['revision'] === 1, 'transaction rollback');
$new['items'][0]['revision'] = 2;
$new['items'][0]['sources'][0]['revision'] = 'old';
$new['items'][0]['actual_consumers'][] = ['feature_id'=>'missing','obligation_id'=>'O2'];
$other = $new; $other['features'][] = ['id'=>'F3'];
$a = launch('import',$base+['expected_revision'=>1,'graph'=>$new],$writer);
$b = launch('import',$base+['expected_revision'=>1,'graph'=>$other],$writer);
[$as,$ar] = finish($a); [$bs,$br] = finish($b);
demand($as+$bs===1 && ($as===0 ? $br['error'] : $ar['error']) === 'revision conflict', 'race has one winner');
$now = runCli('snapshot',$base,$reader);
demand($now['revision'] === 2 && $now['statistics']['unmatched_source_versions']===1 && $now['statistics']['unknown_consumers']===1 && !$now['decisions'], 'frozen gaps and new revision pending');
demand(runCli('snapshot',$base+['revision'=>1],$reader)['graph']==$graph && runCli('import',$import,$writer)==$receipt, 'old freeze and retry preserved');
$old = runCli('snapshot',$base+['revision'=>1],$reader);
demand($old['active_revision'] === 2 && array_column($old['decisions'],'current') === [false,false,false], 'historical decisions are inactive');
demand(!runCli('decision',$revoke,$reviewer)['current'], 'old decision retry never reports current');
$redo = array_merge($first,['prior_decision'=>$d3['id']]);
runCli('decision',$redo,$withReport,'superseded freeze');
mark('concurrent CAS, immutable versions, rollback, stale source and missing consumer');
unlink($reportFile);

require __DIR__ . '/projection_cases.php';
