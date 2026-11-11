<?php
use Newsstand\Groups\GroupCache as G;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var View $view @var string $name @var array $info @var ?array $cached @var list<ThreadNode> $threads @var ?int $older @var ?int $newer */
$parts = explode('.', $name);
$count = max(0, $info['high'] - $info['low'] + 1);
?>
<section class="wrap page-head">
  <nav class="crumbs" aria-label="Breadcrumbs">
    <a href="/">All</a>
    <?php foreach (array_slice($parts, 0, -1) as $i => $part): ?>
      <span aria-hidden="true">›</span>
      <a href="<?= $h(Html::browseUrl(implode('.', array_slice($parts, 0, $i + 1)))) ?>"><?= $h($part) ?></a>
    <?php endforeach ?>
  </nav>
  <h1 class="group-title"><?= $h($name) ?></h1>
  <?php if (($cached[G::DESC] ?? '') !== ''): ?><p class="lead"><?= $x($cached[G::DESC]) ?></p><?php endif ?>
  <p class="muted num small">
    <?= $info['count'] > 0 ? 'About ' . $h(Html::number($info['count'])) . ' articles' : 'No articles at the moment' ?>
    <?php if (($cached[G::NEW] ?? 0) > 0): ?> · <?= $h(Html::number($cached[G::NEW])) ?> new recently<?php endif ?>
  </p>
  <?php if (($cached[G::STATUS] ?? '') === 'm'): ?>
    <p class="notice"><span class="pill standby">Moderated</span> Posts to this group go to a moderator first.</p>
  <?php endif ?>
</section>

<section class="wrap" aria-label="Threads">
<?php if ($threads === []): ?>
  <p class="muted">Dead air. There are no articles in this range.</p>
<?php else: ?>
  <ul class="threads">
    <?php foreach ($threads as $root): ?>
      <li class="thread"><?= $view->render('partials/thread-node', ['node' => $root, 'group' => $name, 'depth' => 0]) ?></li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($newer !== null): ?><a class="btn" href="<?= $h(Html::groupUrl($name, $newer >= $info['high'] + 1 ? null : $newer)) ?>">‹ Newer</a><?php endif ?>
    <?php if ($older !== null): ?><a class="btn" href="<?= $h(Html::groupUrl($name, $older)) ?>">Older ›</a><?php endif ?>
  </nav>
</section>
