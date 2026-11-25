<?php
declare(strict_types=1);

// Invoked from integration.php in its isolated, freshly initialized ZenTao DB.
function projectionCases(): void
{
    global $writer, $reader, $reviewer;
    $publisher = ['TRACE_ROLE'=>'publisher'];
    $base = ['product'=>1,'epic'=>'projection-case'];
    $text = "甲😀\r\n未切";
    $blob = sha1('blob ' . strlen($text) . "\0" . $text);
    $source = ['id'=>'S1','revision'=>1,'path'=>'docs/来源.md','blob'=>$blob];
    $location = $source + ['start_line'=>1,'end_line'=>1,'quote'=>'甲😀'];
    $f1 = ['item_id'=>'I','item_revision'=>1,'feature_id'=>'F1','obligation_id'=>'O'];
    $f2 = array_merge($f1,['feature_id'=>'F2']);
    $features = [['id'=>'F1'],['id'=>'F2']];
    $graph = ['baseline'=>str_repeat('c',40),'sources'=>[$source],'features'=>$features,'items'=>[
        ['id'=>'I','revision'=>1,'summary'=>'shared','sources'=>[$location], 'actual_consumers'=>[$f1,$f2]]
    ]];
    $freeze = runCli('import',$base+['expected_revision'=>0,'graph'=>$graph],$writer);
    $document = ['path'=>$source['path'],'directory_id'=>'docs','blob'=>$blob,'text'=>$text,'state'=>'readable','segments'=>[
        ['start'=>1,'end'=>2,'state'=>'linked','fragment_ids'=>['s']],
        ['start'=>3,'end'=>6,'state'=>'uncut','fragment_ids'=>[]]
    ]];
    $fragment = ['id'=>'s','path'=>$source['path'],'blob'=>$blob,'start'=>1,'end'=>2,'quote'=>'甲😀'];
    $target = ['id'=>'FR-1','kind'=>'clause','start'=>1,'end'=>1,'quote'=>'甲'];
    $spec = ['feature_id'=>'F1','path'=>'specs/f1/spec.md','commit'=>$graph['baseline'],'blob'=>$blob,'text'=>$text,'targets'=>[$target]];
    $view = ['schema'=>1,'baseline'=>$graph['baseline'],'directories'=>[['id'=>'docs','path'=>'docs','parent_id'=>null]],
        'features'=>$features,'documents'=>[$document],'fragments'=>[$fragment],'obligations'=>[$f1,$f2],
        'fragment_obligations'=>[$f1+['fragment_id'=>'s'],$f2+['fragment_id'=>'s']],'specs'=>[$spec],
        'target_obligations'=>[$f1+['target_id'=>'FR-1','kind'=>'clause','blob'=>$blob]]
    ];
    $request = $freeze+['expected_projection'=>0,'projection'=>$view];
    $empty = runCli('projection',$base,$reader);
    demand($empty['projection_revision']===0 && $empty['projection'] === null, 'explicit unpublished state');
    runCli('publish',$request,$writer,'action not granted');
    runCli('publish',$request,$reviewer,'action not granted');
    runCli('projection',array_merge($base,['product'=>2]),$reader,'product not granted');
    runCli('publish',array_merge($request,['basis'=>str_repeat('a',64)]),$publisher,'frozen basis mismatch');
    $invalid = $request;
    $invalid['projection']['documents'][0]['text'] .= '!';
    runCli('publish',$invalid,$publisher,'Git blob does not match');
    $invalid = $request;
    $invalid['projection']['documents'][0]['segments'][1]['start'] = 4;
    runCli('publish',$invalid,$publisher,'gap, overlap');
    $invalid = $request;
    $invalid['projection']['fragments'][0]['quote'] = '假';
    runCli('publish',$invalid,$publisher,'quote differs');
    $invalid = $request;
    $invalid['projection']['fragment_obligations'] = [];
    runCli('publish',$invalid,$publisher,'disconnected fragment');
    $invalid = $request;
    $invalid['projection']['target_obligations'][0]['feature_id'] = 'F2';
    runCli('publish',$invalid,$publisher,'unknown target');
    demand(!runCli('projections',$base,$reader)['projections'], 'invalid publications rolled back');

    $first = runCli('publish',$request,$publisher);
    demand($first['projection_revision'] === 1 && $first === runCli('publish',$request,$publisher),'exact publication retry');
    $read = runCli('projection',$base,$reader);
    demand($read['projection'] == $view && $read['projection_digest'] === $first['projection_digest'], 'round trip with astral character, CRLF and shared obligation');
    demand(!runCli('snapshot',$base,$reader)['decisions'], 'publication grants no review decision');
    $firstChange = $request;
    $firstChange['expected_projection'] = 1;
    $firstChange['projection']['specs'] = [];
    $firstChange['projection']['target_obligations'] = [];
    $secondChange = $firstChange;
    $secondChange['projection']['notes'] = 'other publisher input';
    $a = launch('publish',$firstChange,$publisher);
    $b = launch('publish',$secondChange,$publisher);
    [$ac,$ar] = finish($a); [$bc,$br] = finish($b);
    demand($ac+$bc===1 && ($ac===0 ? $br : $ar)['error']==='projection revision conflict', 'one winner for competing publication');
    $history = runCli('projections',$base,$reader)['projections'];
    demand(array_column($history,'projection_revision') === [2,1], 'both immutable revisions available');
    demand(runCli('projection',$base+['revision'=>1,'projection_revision'=>1],$reader)['projection'] == $view, 'exact historical publication');
    runCli('projection',$base+['projection_revision'=>3],$reader,'unknown projection revision');
    runCli('import',$base+['expected_revision'=>1,'graph'=>$graph],$writer);
    demand(runCli('projection',$base+['revision'=>1],$reader)['active_revision']===2,'superseded freeze marked');
    demand(runCli('projection',$base,$reader)['projection'] === null, 'new freeze cannot inherit projection');
    $stale = $request;
    $stale['expected_projection'] = 2;
    runCli('publish',$stale,$publisher,'superseded freeze');
    mark('publication access, exact cache bytes and ranges, reference integrity, concurrent CAS, immutable history and no implicit gate');
}
projectionCases();
