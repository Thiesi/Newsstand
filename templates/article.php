<?php
use Newsstand\Mime\Article;
use Newsstand\Nntp\GroupName;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var callable $body @var string $group @var int $number @var Article $article @var string $subject @var bool $tooLarge @var int $bytes @var bool $moderated */
$hd = $article->headers;
$date = $hd->get('Date');
$ts = $date !== null ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $date) ?? $date) : false;
$newsgroups = array_filter(array_map('trim', explode(',', (string) $hd->get('Newsgroups'))), [GroupName::class, 'isValid']);
$followup = array_filter(array_map('trim', explode(',', (string) $hd->get('Followup-To'))), [GroupName::class, 'isValid']);
$rows = [
    'From' => $hd->decoded('From'),
    'Reply-To' => $hd->decoded('Reply-To'),
    'Sender' => $hd->decoded('Sender'),
    'Organization' => $hd->decoded('Organization'),
    'Message-ID' => $hd->get('Message-ID'),
];
?>
<article class="wrap narrow-article">
  <nav class="crumbs" aria-label="Breadcrumbs">
    <a href="/">All</a> <span aria-hidden="true">›</span>
    <a href="<?= $h(Html::groupUrl($group)) ?>"><?= $h($group) ?></a> <span aria-hidden="true">›</span>
    <span aria-current="page" class="num">Article <?= $h($number) ?></span>
  </nav>
  <h1 class="article-subject"><?= $x($subject) ?></h1>

  <dl class="headers">
    <?php foreach ($rows as $label => $value): if ($value === null || $value === '') continue; ?>
      <div><dt><?= $h($label) ?></dt><dd<?= $label === 'Message-ID' ? ' class="mono small"' : '' ?>><?= $x($value) ?></dd></div>
    <?php endforeach ?>
    <?php if ($ts !== false && $ts !== null): ?>
      <div><dt>Date</dt><dd class="num"><time datetime="<?= $h(Html::isoDate($ts)) ?>"><?= $h(Html::date($ts)) ?></time></dd></div>
    <?php endif ?>
    <?php if ($newsgroups !== []): ?>
      <div><dt>Newsgroups</dt><dd><?php foreach (array_values($newsgroups) as $i => $ng): ?><?= $i ? ', ' : '' ?><a href="<?= $h(Html::groupUrl($ng)) ?>"><?= $h($ng) ?></a><?php endforeach ?></dd></div>
    <?php endif ?>
    <?php if ($followup !== []): ?>
      <div><dt>Follow-ups to</dt><dd><?php foreach (array_values($followup) as $i => $ng): ?><?= $i ? ', ' : '' ?><a href="<?= $h(Html::groupUrl($ng)) ?>"><?= $h($ng) ?></a><?php endforeach ?></dd></div>
    <?php endif ?>
  </dl>

  <?php if ($moderated): ?><p class="notice small"><span class="pill standby">Moderated</span> This group is moderated.</p><?php endif ?>

  <?php if ($tooLarge): ?>
    <p class="notice">This article is <?= $h(Html::bytes($bytes)) ?>, too large to show here. It most likely carries a binary attachment.</p>
  <?php else: ?>
  <div class="prose article-body">
    <?php foreach ($article->blocks as $block): ?>
      <?php if ($block['signature']): ?>
        <div class="sig"><?= $body($block['text']) ?></div>
      <?php elseif ($block['level'] > 0): ?>
        <div class="q q<?= min(4, $block['level']) ?>"><?= $body($block['text']) ?></div>
      <?php else: ?>
        <div class="t"><?= $body($block['text']) ?></div>
      <?php endif ?>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <?php if ($article->attachments !== []): ?>
  <section class="attachments" aria-labelledby="h-att">
    <h2 id="h-att" class="section-title">Attachments</h2>
    <p class="muted small">Attachments are listed but not shown.</p>
    <ul>
      <?php foreach ($article->attachments as $a): ?>
        <li><?= $x($a['name']) ?> <span class="muted small num">(<?= $h($a['type']) ?>, <?= $h(Html::bytes($a['size'])) ?>)</span></li>
      <?php endforeach ?>
    </ul>
  </section>
  <?php endif ?>

  <nav class="pager" aria-label="Article navigation">
    <a class="btn" href="<?= $h(Html::groupUrl($group, $number + 1)) ?>">Show in thread list</a>
    <a class="btn" href="<?= $h(Html::groupUrl($group)) ?>">Back to <?= $h($group) ?></a>
  </nav>
</article>
