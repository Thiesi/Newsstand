<?php
use Newsstand\Groups\GroupCache as G;
use Newsstand\Web\Html;
use Newsstand\Web\LevelPager;
/** @var callable $h @var callable $x @var View $view @var string $prefix @var LevelPager $pager @var ?array $self @var G $cache */
$parts = $prefix === '' ? [] : explode('.', $prefix);
$base = Html::browseUrl($prefix);
$total = count($pager->children);
?>
<section class="wrap page-head">
  <nav class="crumbs" aria-label="Breadcrumbs">
    <a href="/">Start</a>
    <span aria-hidden="true">›</span>
    <?php if ($parts === []): ?><span aria-current="page">All hierarchies</span><?php else: ?><a href="/browse">All</a><?php endif ?>
    <?php foreach ($parts as $i => $part): $p = implode('.', array_slice($parts, 0, $i + 1)); ?>
      <span aria-hidden="true">›</span>
      <?php if ($i === count($parts) - 1): ?><span aria-current="page"><?= $h($part) ?></span>
      <?php else: ?><a href="<?= $h(Html::browseUrl($p)) ?>"><?= $h($part) ?></a><?php endif ?>
    <?php endforeach ?>
  </nav>
  <h1 class="group-title"><?= $prefix === '' ? 'All hierarchies' : $h($prefix) . '.*' ?></h1>
  <p class="muted num small"><?= $h(Html::number($total)) ?> <?= $prefix === '' ? 'top-level hierarchies' : ($total === 1 ? 'entry' : 'entries') ?> at this level</p>
  <?php if ($self !== null): ?>
    <p><a class="btn" href="<?= $h(Html::groupUrl($prefix)) ?>">Read <?= $h($prefix) ?> itself</a></p>
  <?php endif ?>
</section>

<?php if ($pager->big): ?>
<nav class="wrap letters" aria-label="Jump to letter">
  <a href="<?= $h($base) ?>"<?= $pager->letter === null ? ' aria-current="page"' : '' ?>>Largest</a>
  <?php foreach ($pager->letters as $key => $n): ?>
    <a href="<?= $h($base . '?letter=' . rawurlencode($key)) ?>"<?= $pager->letter === $key ? ' aria-current="page"' : '' ?> title="<?= $h($n) ?> entries"><?= $h($key === 'other' ? '#' : strtoupper($key)) ?></a>
  <?php endforeach ?>
</nav>
<?php endif ?>

<section class="wrap" aria-label="Groups and sub-hierarchies">
  <?php if ($pager->big && $pager->letter === null): ?>
    <h2 class="section-title">Largest <?= $prefix === '' ? 'hierarchies' : 'entries' ?></h2>
    <p class="muted small">Pick a letter above for the full list.</p>
  <?php elseif ($pager->letter !== null): ?>
    <h2 class="section-title"><?= $h($pager->letter === 'other' ? 'Other' : strtoupper($pager->letter)) ?><?= $pager->pages > 1 ? ' · page ' . $h($pager->page) . ' of ' . $h($pager->pages) : '' ?></h2>
  <?php endif ?>
  <?= $view->render('partials/node-list', ['prefix' => $prefix, 'children' => $pager->entries, 'cache' => $cache]) ?>
  <?php if ($pager->pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($pager->page > 1): ?><a class="btn" href="<?= $h($base . '?letter=' . rawurlencode($pager->letter) . '&page=' . ($pager->page - 1)) ?>">‹ Previous</a><?php endif ?>
    <?php if ($pager->page < $pager->pages): ?><a class="btn" href="<?= $h($base . '?letter=' . rawurlencode($pager->letter) . '&page=' . ($pager->page + 1)) ?>">Next ›</a><?php endif ?>
  </nav>
  <?php endif ?>
</section>
