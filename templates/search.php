<?php
use Newsstand\Groups\GroupCache as G;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var View $view @var string $q @var int $total @var list<string> $results @var G $cache @var bool $built */
?>
<section class="wrap page-head">
  <h1>Search groups</h1>
  <?= $view->render('partials/search-form', ['q' => $q]) ?>
</section>
<section class="wrap" aria-live="polite">
<?php if (!$built): ?>
  <p class="muted">The group list has not been built yet, so search is not available.</p>
<?php elseif ($q !== '' && $total === 0): ?>
  <p>No groups match <strong><?= $h($q) ?></strong>. Try fewer or shorter words, or <a href="/">browse the hierarchies</a>.</p>
<?php elseif ($q !== ''): ?>
  <p class="muted num"><?= $h(Html::number($total)) ?> <?= $total === 1 ? 'group matches' : 'groups match' ?><?= $total > count($results) ? ', showing the best ' . $h(count($results)) : '' ?>.</p>
  <ul class="nodes">
  <?php foreach ($results as [$name, $g]): ?>
    <li class="node">
      <div class="node-main">
        <a class="node-name" href="<?= $h(Html::groupUrl($name)) ?>"><?= $h($name) ?></a>
        <?php if ($g[G::STATUS] === 'm'): ?><span class="pill standby">Moderated</span><?php endif ?>
        <?php if ($g[G::DESC] !== ''): ?><p class="desc"><?= $x($g[G::DESC]) ?></p><?php endif ?>
      </div>
      <div class="node-meta num small muted">
        <?php if ($g[G::NEW] > 0): ?><span class="new"><?= $h(Html::number($g[G::NEW])) ?> new</span><?php endif ?>
      </div>
    </li>
  <?php endforeach ?>
  </ul>
<?php endif ?>
</section>
