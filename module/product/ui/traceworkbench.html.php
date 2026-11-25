<?php
declare(strict_types=1);

namespace zin;

$epicKey ??= '';
$revision ??= 0;
$projectionRevision ??= 0;

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$bodyText = static fn(string $value): string => str_replace("\r", '&#13;', $escape($value));
$url = static function(string $type = 'directory', int $number = 0, array $replace = array()) use ($productID, $epicKey, $revision, $projectionRevision): string
{
    return createLink('product', 'traceworkbench', array_replace(array(
        'productID' => $productID,
        'epicKey' => $epicKey ?? '',
        'revision' => $revision ?? 0,
        'projectionRevision' => $projectionRevision ?? 0,
        'kind' => $type,
        'entry' => $number
    ), $replace));
};
$link = static fn(string $type, int $index, string $label): string => '<a href="' . $escape($url($type, $index + 1)) . '">' . $escape($label) . '</a>';

$stateNames = array(
    'linked' => '已关联', 'disposed' => '已有处置', 'unlinked' => '已切分 · 无义务关联', 'uncut' => '尚未切分',
    'unassigned' => '未分配', 'ambiguous_source' => '来源待确认', 'no_source' => '缺少来源',
    'awaiting_spec' => '待编制规格', 'missing_targets' => '缺少规格目标', 'mapped' => '已登记映射',
    'readable' => '可读', 'unreadable' => '原件不可读', 'unextractable' => '无法提取正文',
    'clause' => '条款', 'scenario' => '验收场景', 'shared_reference' => '共享约束引用',
    'missing_feature_responsibility' => '功能缺少职责',
    'unresolved_exact_location' => '无法解析精确位置',
    'graph_source_missing_manifest' => '来源文件未登记到清单'
);
$stateLabel = static fn(string $state): string => $stateNames[$state] ?? ($state === '' ? '未登记' : $state);
$badge = static fn(string $state): string => '<span class="trace-badge" data-state="' . $escape($state) . '">' . $escape($stateLabel($state)) . '</span>';
$textLabel = static function(array $row, array $fields): string
{
    foreach($fields as $field)
    {
        $value = $row[$field] ?? null;
        if(is_string($value) && trim($value) !== '') return $value;
    }
    return '';
};
$featureTitle = static function(array $feature) use ($textLabel): string
{
    $name = $textLabel($feature, array('title', 'name'));
    return $name === '' || $name === $feature['id'] ? $feature['id'] : $feature['id'] . ' · ' . $name;
};
$location = static function(array $row): string
{
    $lines = isset($row['start_line'], $row['end_line']) ? "第 {$row['start_line']}–{$row['end_line']} 行 · " : '';
    return $lines . "字符 {$row['start']}–{$row['end']}";
};
$targetTitle = static function(array $row) use ($stateLabel, $textLabel): string
{
    $firstLine = trim((string)strtok($textLabel($row, array('title', 'name', 'quote')), "\r\n"), "# \t");
    $part = mb_strimwidth($firstLine, 0, 82, '…', 'UTF-8');
    return $stateLabel($row['kind']) . ' · ' . $row['id'] . ($part === '' || $part === $row['id'] ? '' : ' · ' . $part);
};

$renderEvidence = static function(mixed $evidence) use ($escape, $bodyText): void
{
    if(is_string($evidence) && $evidence !== '')
    {
        echo '<p class="trace-explanation">证据 ' . $escape($evidence) . '</p>';
        return;
    }
    if(!is_array($evidence) || !isset($evidence['path'], $evidence['blob'], $evidence['start_line'], $evidence['end_line'], $evidence['quote'])) return;
    ?>
    <details class="trace-history">
      <summary>证据 <?= $escape($evidence['path']) ?> · 第 <?= $escape($evidence['start_line']) ?>–<?= $escape($evidence['end_line']) ?> 行</summary>
      <p>原件 blob <code><?= $escape($evidence['blob']) ?></code></p>
      <pre class="trace-quote"><code><?= $bodyText($evidence['quote']) ?></code></pre>
    </details>
    <?php
};
$renderDisposition = static function(array $disposition) use ($escape, $renderEvidence): void
{
    ?>
    <div class="trace-explanation">
      <?= $escape($disposition['disposition'] ?? '') ?> <?= $escape($disposition['reason'] ?? '') ?>
      <?php if(!empty($disposition['owner'])): ?> · 责任方 <?= $escape($disposition['owner']) ?><?php endif; ?>
    </div>
    <?php
    $renderEvidence($disposition['evidence'] ?? null);
};
$renderIssues = static function(array $issues, string $heading) use ($escape, $stateLabel): void
{
    if(!$issues) return;
    ?>
    <details class="trace-history">
      <summary><?= $escape($heading) ?> · <?= count($issues) ?> 项</summary>
      <ul class="trace-relations">
        <?php foreach($issues as $issue):
            $code = $issue['code'] ?? '';
            unset($issue['code']);
        ?>
          <li>
            <strong><?= $escape($stateLabel($code)) ?></strong>
            <?php foreach($issue as $key => $value): ?>
              <div class="trace-explanation"><?= $escape($key) ?> <code><?= $escape(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code></div>
            <?php endforeach; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php
};
$history = static function(array $rows, string $title) use ($escape): void
{
    ?>
    <section class="trace-subsection">
      <h3><?= $escape($title) ?></h3>
      <?php if(!$rows): ?>
        <p class="trace-muted">本准确版本暂无匹配的独审或机器结构记录。</p>
      <?php else: ?>
        <p class="trace-muted">按准确目标列出历史记录。有效放行以审计入口判定。</p>
        <?php foreach($rows as $row):
            $evidence = json_decode($row['evidence'], true);
            $machine = $row['decision_kind'] === 'machine_structure';
            $result = $row['decision'] === 'accepted' ? ($machine ? '结构检查通过' : '独审通过') : '要求修改';
            $cutoff = (int)$row['is_current'] === 1 ? '当前记录' : '历史记录 · 已失效';
        ?>
          <details class="trace-history">
            <summary><?= $escape("#{$row['id']} · {$cutoff} · {$result} · {$row['reviewer']} · {$row['created_at']}") ?></summary>
            <p>目标摘要 <code><?= $escape($row['target_revision']) ?></code></p>
            <?php if($row['epic_decision'] !== null): ?><p>关联上游决定 #<?= $escape($row['epic_decision']) ?></p><?php endif; ?>
            <pre><?= $escape(is_string($evidence) ? $evidence : json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php
};

$versions ??= array();
ob_start();
?>
<section class="trace-workbench" aria-labelledby="trace-heading">
  <header class="trace-heading">
    <div>
      <h1 id="trace-heading">需求追溯</h1>
      <p><?= $escape($traceProductName) ?></p>
    </div>
    <?php if($traceState === 'ready'): ?>
      <a class="btn trace-permalink" href="<?= $escape($url($kind, $entry)) ?>">打开固定版本</a>
    <?php endif; ?>
  </header>

  <?php if($versions): ?>
    <section class="trace-version" aria-label="版本">
      <details class="trace-picker">
        <summary>Epic <strong><?= $escape(!empty($epicKey) && isset($versions[$epicKey]) ? hex2bin($epicKey) : '选择') ?></strong></summary>
        <ul>
          <?php foreach($versions as $key => $list): ?>
            <li><a href="<?= $escape($url('directory', 0, array('epicKey' => $key, 'revision' => 0, 'projectionRevision' => 0))) ?>" <?= (string)$key === $epicKey ? 'aria-current="true"' : '' ?>><?= $escape(hex2bin((string)$key)) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </details>
      <?php if(!empty($versions[$epicKey])): ?>
        <details class="trace-picker">
          <summary>冻结版本 <strong><?= $escape($revision) ?></strong></summary>
          <ul>
            <?php foreach($versions[$epicKey] as $number => $list): ?>
              <li><a href="<?= $escape($url('directory', 0, array('revision' => $number, 'projectionRevision' => 0))) ?>" <?= $number === $revision ? 'aria-current="true"' : '' ?>>冻结 <?= $escape($number) ?><?= $list ? '' : ' · 无快照' ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <?php if(!empty($versions[$epicKey][$revision])): ?>
        <details class="trace-picker">
          <summary>追溯快照 <strong><?= $escape($projectionRevision) ?></strong></summary>
          <ul>
            <?php foreach($versions[$epicKey][$revision] as $number): ?>
              <li><a href="<?= $escape($url('directory', 0, array('projectionRevision' => $number))) ?>" <?= $number === $projectionRevision ? 'aria-current="true"' : '' ?>>快照 <?= $escape($number) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if($traceState !== 'ready'):
      $messages = array(
          'empty' => '该产品暂无冻结 Epic。',
          'unpublished' => '这个冻结版本尚未发布追溯快照。可以选择其他冻结版本。',
          'unknown' => '找不到请求的版本或追溯对象。可以重新选择 Epic 或版本。',
          'unavailable' => '暂时无法读取追溯数据，请检查安装或稍后重试。'
      );
  ?>
    <div class="trace-panel trace-notice" role="status"><?= $escape($messages[$traceState]) ?></div>
  <?php else:
      $payload = $projection['payload'];
      $mode = match($kind)
      {
          'features', 'feature', 'obligation' => 'features',
          'specs', 'spec', 'target' => 'specs',
          default => 'directory'
      };
      $docIndex = match($kind)
      {
          'document' => $entry - 1,
          'fragment' => $selected['documentIndex'],
          default => null
      };
      $document = $docIndex === null ? null : $navigation->entry('document', $docIndex + 1);
      $spec = match($kind)
      {
          'spec' => $selected,
          'target' => $navigation->entry('spec', $selected['specIndex'] + 1),
          default => null
      };
      $fragmentLink = static function(int $index) use ($navigation, $link, $location): string
      {
          $fragment = $navigation->entry('fragment', $index + 1);
          return $link('fragment', $index, $fragment['path'] . ' · ' . $location($fragment));
      };
      $obligationLink = static function(int $index) use ($navigation, $link, $badge, $textLabel): string
      {
          $row = $navigation->entry('obligation', $index + 1);
          $name = $textLabel($row, array('description', 'summary'));
          return $link('obligation', $index, "{$row['obligation_id']} · {$row['feature_id']}" . ($name === '' ? '' : " · $name")) . ' ' . $badge((string)($row['state'] ?? ''));
      };
      $featureLink = static function(int $index) use ($navigation, $link, $featureTitle): string
      {
          return $link('feature', $index, $featureTitle($navigation->entry('feature', $index + 1)));
      };
      $targetLink = static function(int $index) use ($navigation, $link, $targetTitle): string
      {
          $target = $navigation->entry('target', $index + 1);
          return $link('target', $index, $targetTitle($target));
      };
      $specLink = static function(int $index) use ($navigation, $link): string
      {
          $spec = $navigation->entry('spec', $index + 1);
          return $link('spec', $index, $spec['path']);
      };
      $renderRelations = static function(array $ids, \Closure $render, string $empty) use ($escape): void
      {
          if(!$ids)
          {
              echo '<p class="trace-muted">' . $escape($empty) . '</p>';
              return;
          }

          echo '<ul class="trace-relations">';
          foreach(array_unique($ids) as $index) echo '<li>' . $render($index) . '</li>';
          echo '</ul>';
      };
  ?>
    <nav class="trace-tabs" aria-label="追溯视图">
      <?php foreach(array('directory' => '来源目录', 'features' => '功能职责', 'specs' => '规格条款与场景') as $tab => $label): ?>
        <a href="<?= $escape($url($tab)) ?>" <?= $mode === $tab ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="trace-layout">
      <aside class="trace-panel trace-index" aria-label="当前索引">
        <?php if($mode === 'directory'):
            $children = $files = array();
            foreach($payload['directories'] ?? array() as $directory)
            {
                $children[$directory['parent_id'] ?? ''][] = $directory;
            }
            foreach($navigation->list('document') as $index => $row) $files[$row['directory_id'] ?? ''][$index] = $row;
            $renderTree = static function(string $id) use (&$renderTree, $children, $files, $docIndex, $escape, $url, $stateLabel): void
            {
                if(empty($children[$id]) && empty($files[$id])) return;
                echo '<ul class="trace-tree">';
                foreach($children[$id] ?? array() as $directory)
                {
                    if($directory['id'] === $id) continue;
                    $name = basename($directory['path']) ?: $directory['id'];
                    echo '<li><details open><summary>' . $escape($name) . '</summary>';
                    $renderTree($directory['id']);
                    echo '</details></li>';
                }
                foreach($files[$id] ?? array() as $index => $file)
                {
                    $current = $docIndex === $index ? ' aria-current="page"' : '';
                    $label = basename($file['path']);
                    if(($file['state'] ?? '') !== 'readable') $label .= ' · ' . $stateLabel($file['state'] ?? '');
                    echo '<li><a href="' . $escape($url('document', $index + 1)) . '"' . $current . '>' . $escape($label) . '</a></li>';
                }
                echo '</ul>';
            };
        ?>
          <h2>原件目录</h2>
          <?php $renderTree(''); ?>
        <?php elseif($mode === 'features'): ?>
          <h2>功能</h2>
          <ul class="trace-tree">
            <?php foreach($navigation->list('feature') as $index => $feature): ?>
              <li><?= $featureLink($index) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <h2>规格</h2>
          <ul class="trace-tree">
            <?php foreach($navigation->list('spec') as $index => $row): ?>
              <li><?= $specLink($index) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </aside>

      <main class="trace-panel trace-content">
        <?php if($kind === 'directory'): ?>
          <h2>来源目录</h2>
          <p class="trace-muted">从左侧目录选择原件。在全文中查看切分边界、功能义务和缺口。</p>
          <dl class="trace-overview">
            <?php foreach(array('document' => '原件', 'fragment' => '片段', 'feature' => '功能', 'obligation' => '义务', 'spec' => '规格', 'target' => '条款与场景') as $type => $label): ?>
              <div><dt><?= $escape($label) ?></dt><dd><?= count($navigation->list($type)) ?></dd></div>
            <?php endforeach; ?>
          </dl>
          <section class="trace-subsection">
            <h3>来源检查</h3>
            <?php
            $renderIssues($payload['structural_issues'] ?? array(), '结构问题');
            $renderIssues($payload['coverage_issues'] ?? array(), '覆盖问题');
            if(empty($payload['structural_issues']) && empty($payload['coverage_issues'])) echo '<p class="trace-muted">此快照无登记的结构或覆盖问题。</p>';
            ?>
          </section>
          <?php $history($epicHistory, 'Epic 审计记录'); ?>
        <?php elseif($document):
            $segments = $document['segments'] ?? array();
            $highlightFragment = $kind === 'fragment' ? $selected : null;
        ?>
          <h2><?= $escape($document['path']) ?></h2>
          <div class="trace-identity">原件 blob <code><?= $escape($document['blob']) ?></code> <?= $badge($document['state']) ?></div>
          <?php $sourceVersions = $document['source_versions'] ?? array(); ?>
          <?php if($sourceVersions): ?>
            <details class="trace-history">
              <summary>来源身份<?= count($sourceVersions) === 1 ? ' · ' . $escape($sourceVersions[0]['source_id']) . ' @ ' . $escape($sourceVersions[0]['source_revision']) : ' · ' . count($sourceVersions) . ' 个登记' ?></summary>
              <ul class="trace-relations">
                <?php foreach($sourceVersions as $source): ?>
                  <li><code><?= $escape($source['source_id']) ?> @ <?= $escape($source['source_revision']) ?></code> · 声明的 blob <code><?= $escape($source['declared_blob']) ?></code></li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
          <?php if(!empty($document['file_disposition'])): ?>
            <details class="trace-history">
              <summary>文件级处置<?= !empty($document['file_disposition_verified']) ? ' · 证据已核对' : ' · 暂无核对依据' ?></summary>
              <?php $renderDisposition($document['file_disposition']); ?>
            </details>
          <?php endif; ?>
          <?php if($highlightFragment): ?>
            <section class="trace-subsection trace-selected">
              <div class="trace-context"><a href="<?= $escape($url('document', $docIndex + 1)) ?>">返回原件概览</a></div>
              <h3>片段 <?= $escape($highlightFragment['id']) ?></h3>
              <p class="trace-muted"><?= $escape($location($highlightFragment)) ?></p>
              <pre class="trace-quote"><code><?= $bodyText($highlightFragment['quote']) ?></code></pre>
              <h4>功能义务</h4>
              <?php $renderRelations($highlightFragment['obligations'] ?? array(), $obligationLink, '该片段无已登记义务关联。'); ?>
              <?php
              $consumers = array();
              foreach($highlightFragment['obligations'] ?? array() as $index)
              {
                  $owner = $navigation->entry('obligation', $index + 1)['featureIndex'];
                  if($owner !== null) $consumers[] = $owner;
              }
              if($consumers) $renderRelations($consumers, $featureLink, '');
              ?>
            </section>
          <?php endif; ?>

          <section class="trace-subsection">
            <h3>原件全文</h3>
            <?php if(!isset($document['text'])): ?>
              <p class="trace-muted"><?= $escape($stateLabel($document['state'])) ?>。此快照没有可展示的正文。</p>
            <?php elseif($document['text'] === ''): ?>
              <p class="trace-muted">原件为空。</p>
            <?php else: ?>
              <div class="trace-legend" aria-label="全文标记">
                <?php foreach(array('linked', 'disposed', 'unlinked', 'uncut') as $state) echo $badge($state); ?>
              </div>
              <pre class="trace-source" aria-label="原件全文"><code><?php
                if(!$segments) echo $bodyText($document['text']);
                foreach($segments as $index => $region)
                {
                    $text = mb_substr($document['text'], $region['start'] - 1, $region['end'] - $region['start'] + 1, 'UTF-8');
                    $chosen = $highlightFragment && in_array($highlightFragment['id'], $region['fragment_ids'] ?? array(), true);
                    echo '<span id="trace-range-' . ($index + 1) . '" data-state="' . $escape($region['state']) . '" title="' . $escape($stateLabel($region['state']) . ' · ' . $location($region)) . '"' . ($chosen ? ' class="trace-selected-range"' : '') . '>' . $bodyText($text) . '</span>';
                }
              ?></code></pre>
            <?php endif; ?>
          </section>

          <section class="trace-subsection">
            <h3>缺口与处置</h3>
            <?php
            $forDocument = static fn(array $issue): bool => ($issue['path'] ?? '') === $document['path'];
            $renderIssues(array_filter($payload['structural_issues'] ?? array(), $forDocument), '本文件结构问题');
            $renderIssues(array_filter($payload['coverage_issues'] ?? array(), $forDocument), '本文件覆盖问题');
            $visibleSegments = array_filter($segments, static function(array $region) use ($document): bool
            {
                if($region['state'] === 'linked') return false;
                if($region['state'] === 'disposed') return true;
                $text = mb_substr($document['text'], $region['start'] - 1, $region['end'] - $region['start'] + 1, 'UTF-8');
                return preg_match('/[^\s\x{feff}]/u', $text) === 1;
            });
            ?>
            <?php if(!$visibleSegments): ?><p class="trace-muted">此快照未登记需显示的未关联区间或处置。</p><?php endif; ?>
            <?php if($visibleSegments): ?>
              <ul class="trace-relations">
                <?php foreach($visibleSegments as $index => $region): ?>
                  <li>
                    <?= $badge($region['state']) ?> <a href="#trace-range-<?= $index + 1 ?>"><?= $escape($location($region)) ?></a>
                    <?php foreach($region['dispositions'] ?? array() as $disposition) $renderDisposition($disposition); ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <h3>原件片段</h3>
            <?php $renderRelations($document['fragments'] ?? array(), $fragmentLink, '此快照没有该原件的片段。'); ?>
          </section>

        <?php elseif($kind === 'features'): ?>
          <h2>功能职责</h2>
          <?php $renderRelations(array_keys($navigation->list('feature')), $featureLink, '此快照无已登记功能。'); ?>
        <?php elseif($kind === 'feature'): ?>
          <h2><?= $escape($featureTitle($selected)) ?></h2>
          <?php $responsibility = $textLabel($selected, array('responsibility', 'description', 'scope')); ?>
          <p class="trace-prose"><?= $escape($responsibility === '' ? '此快照未登记该功能的职责说明。' : $responsibility) ?></p>
          <?php $renderEvidence($selected['responsibility_evidence'] ?? null); ?>
          <section class="trace-subsection">
            <h3>功能义务</h3>
            <?php $renderRelations($selected['obligations'] ?? array(), $obligationLink, '此功能无已登记义务。'); ?>
          </section>
          <section class="trace-subsection">
            <h3>规格</h3>
            <?php $renderRelations($selected['specs'] ?? array(), $specLink, '此功能暂无已登记规格版本。'); ?>
          </section>
        <?php elseif($kind === 'obligation'): ?>
          <h2>功能义务 <?= $escape($selected['obligation_id']) ?></h2>
          <div class="trace-identity">需求项 <code><?= $escape($selected['item_id']) ?></code> · 版本 <code><?= $escape($selected['item_revision']) ?></code> <?= $badge((string)($selected['state'] ?? '')) ?></div>
          <p class="trace-prose"><?= $escape($textLabel($selected, array('description', 'summary'))) ?></p>
          <section class="trace-subsection">
            <h3>功能职责</h3>
            <?php if($selected['featureIndex'] !== null) echo $featureLink($selected['featureIndex']); ?>
            <?php if($selected['featureIndex'] === null): ?><p class="trace-muted">该功能未登记。</p><?php endif; ?>
            <h3>来源片段</h3>
            <?php $renderRelations($selected['fragments'] ?? array(), $fragmentLink, '该义务没有已登记的来源片段。'); ?>
            <h3>规格条款与场景</h3>
            <?php $renderRelations($selected['targets'] ?? array(), $targetLink, '该义务暂无目标证据。'); ?>
            <?php if(!empty($selected['required_targets'])):
                $missingTargets = array_column($selected['missing_targets'] ?? array(), 'reason', 'slot');
            ?>
              <details class="trace-history">
                <summary>应具备的证据</summary>
                <ul class="trace-relations">
                  <?php foreach($selected['required_targets'] as $slot): ?>
                    <li><?= $badge($slot['kind']) ?> <?= $escape($slot['independent_label'] ?? $slot['id']) ?>
                      <?php if(isset($missingTargets[$slot['id']])): ?>
                        <span class="trace-badge" data-state="unlinked">缺少</span> <?= $escape($missingTargets[$slot['id']]) ?>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
            <?php if(!empty($selected['scenario_exception'])): ?>
              <details class="trace-history">
                <summary>场景例外</summary>
                <p><?= $escape($selected['scenario_exception']['reason']) ?></p>
                <?php $renderEvidence($selected['scenario_exception']['evidence'] ?? null); ?>
              </details>
            <?php endif; ?>
          </section>

        <?php elseif($kind === 'specs'): ?>
          <h2>规格条款与场景</h2>
          <?php $renderRelations(array_keys($navigation->list('spec')), $specLink, '这个快照没有已登记的规格。'); ?>
        <?php elseif($spec): ?>
          <h2><?= $escape($spec['path']) ?></h2>
          <div class="trace-identity">规格提交 <code><?= $escape($spec['commit']) ?></code> · blob <code><?= $escape($spec['blob']) ?></code></div>
          <?php
          $checkState = array('passed' => '机械检查通过', 'failed' => '机械检查有缺口', 'pending' => '待机械检查');
          $checkIssues = array_merge($spec['check_issues'] ?? array(), $spec['anchor_issues'] ?? array());
          ?>
          <p class="trace-muted">候选规格状态 <?= $escape($checkState[$spec['check_state'] ?? ''] ?? '此快照暂无机械检查结果') ?></p>
          <?php if($checkIssues): ?>
            <details class="trace-history">
              <summary>机械检查发现 <?= count($checkIssues) ?> 项</summary>
              <pre><?= $escape(json_encode($checkIssues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            </details>
          <?php endif; ?>
          <?php if($spec['featureIndex'] !== null): ?><p class="trace-context">功能 <?= $featureLink($spec['featureIndex']) ?></p><?php endif; ?>
          <?php if($kind === 'target'): ?>
            <section class="trace-subsection trace-selected">
              <div class="trace-context"><?= $specLink($selected['specIndex']) ?></div>
              <h3><?= $escape($targetTitle($selected)) ?></h3>
              <p class="trace-muted"><?= $escape($location($selected)) ?></p>
              <pre class="trace-quote"><code><?= $bodyText($selected['quote']) ?></code></pre>
              <h4>关联功能义务</h4>
              <?php $renderRelations($selected['obligations'] ?? array(), $obligationLink, '该目标暂无义务关联证据。'); ?>
              <h4>反查来源片段</h4>
              <?php
              $sourceIndexes = array();
              foreach($selected['obligations'] ?? array() as $index)
              {
                  $row = $navigation->entry('obligation', $index + 1);
                  $sourceIndexes = array_merge($sourceIndexes, $row['fragments'] ?? array());
              }
              $renderRelations($sourceIndexes, $fragmentLink, '该目标暂无来源关联证据。');
              ?>
            </section>
          <?php endif; ?>
          <section class="trace-subsection">
            <h3>条款与场景</h3>
            <?php
            $listTargets = static function(int $index) use ($targetLink, $navigation): string
            {
                $row = $navigation->entry('target', $index + 1);
                return $targetLink($index) . (empty($row['obligations']) ? ' <span class="trace-badge" data-state="unlinked">无关联证据</span>' : '');
            };
            $renderRelations($spec['targetsIndex'] ?? array(), $listTargets, '这份规格暂无已定位条款或场景。');
            ?>
          </section>
          <section class="trace-subsection">
            <h3>规格全文</h3>
            <?php if(isset($spec['text'])): ?>
              <pre class="trace-source" aria-label="规格全文"><code><?= $bodyText($spec['text']) ?></code></pre>
            <?php else: ?><p class="trace-muted">此快照无可读规格原文。</p><?php endif; ?>
          </section>
          <?php $history($specHistory, '此规格版本的独审记录'); ?>
        <?php else: ?>
          <h2>片段 <?= $escape($selected['id']) ?></h2>
          <p class="trace-muted">此快照没有可匹配的原件版本。</p>
        <?php endif; ?>
      </main>
    </div>
    <details class="trace-versions-details">
      <summary>版本依据</summary>
      <dl>
        <dt>冻结 Git 提交</dt><dd><code><?= $escape($payload['baseline']) ?></code></dd>
        <dt>冻结依据 SHA-256</dt><dd><code><?= $escape($projection['basis']) ?></code></dd>
        <dt>快照 SHA-256</dt><dd><code><?= $escape($projection['digest']) ?></code></dd>
        <dt>发布时间</dt><dd><?= $escape($projection['created_at']) ?></dd>
      </dl>
    </details>
  <?php endif; ?>
</section>
<?php
html(ob_get_clean());
