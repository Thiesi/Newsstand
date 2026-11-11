<?php
use Newsstand\Groups\GroupCache as G;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var View $view @var bool $built @var ?array $meta @var array $top @var list<string> $active @var G $cache */
?>
<section class="wrap page-head">
  <div class="eyebrow">Channel 4 · Usenet</div>
  <h1>Newsgroups</h1>
  <p class="lead">Read Usenet in your browser. All <?= $built ? $h(Html::number((int) $meta['count'])) . ' ' : '' ?>groups on the NETWORK XXIII news server, no account needed.</p>
  <?= $view->render('partials/search-form', ['q' => '']) ?>
</section>

<?php if (!$built): ?>
<section class="wrap">
  <div class="tile"><p>The group list has not been built yet. Run <code>bin/refresh-groups.php</code>.</p></div>
</section>
<?php else: ?>
<div class="wrap two-col">
  <section aria-labelledby="h-hier">
    <h2 id="h-hier" class="section-title">Largest hierarchies</h2>
    <?= $view->render('partials/node-list', ['prefix' => '', 'children' => $top, 'cache' => $cache]) ?>
    <p><a class="btn" href="/browse">All <?= $h(Html::number($topCount)) ?> hierarchies, A–Z</a></p>
  </section>
  <section aria-labelledby="h-active">
    <h2 id="h-active" class="section-title">On air</h2>
    <p class="muted small">Most new articles since <?= $h(Html::date((int) $meta['snapshot'])) ?>.</p>
    <?php if ($active === []): ?>
      <p class="muted">Quiet so far. Activity shows up after the group list has been refreshed a few times.</p>
    <?php else: ?>
    <ol class="active-list">
      <?php foreach ($active as [$name, $new]): ?>
      <li><a href="<?= $h(Html::groupUrl($name)) ?>"><?= $h($name) ?></a> <span class="num small muted"><?= $h(Html::number($new)) ?> new</span></li>
      <?php endforeach ?>
    </ol>
    <?php endif ?>
  </section>
</div>
<?php endif ?>
