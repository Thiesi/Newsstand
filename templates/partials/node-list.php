<?php
use Newsstand\Groups\GroupCache as G;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var string $prefix @var array $children @var GroupCache $cache */
?>
<ul class="nodes">
<?php foreach ($children as $part => $node):
    $full = $prefix === '' ? (string) $part : $prefix . '.' . $part;
    $group = $node[G::N_RECORD] ?? null;
    ?>
  <li class="node">
    <div class="node-main">
      <?php if ($node[G::N_HAS_CHILDREN]): ?>
        <a class="node-name" href="<?= $h(Html::browseUrl($full)) ?>"><?= $h((string) $part) ?><span class="muted">.*</span></a>
      <?php else: ?>
        <a class="node-name" href="<?= $h(Html::groupUrl($full)) ?>"><?= $h((string) $part) ?></a>
      <?php endif ?>
      <?php if ($group !== null && $group[G::STATUS] === 'm'): ?><span class="pill standby">Moderated</span><?php endif ?>
      <?php if ($group !== null && $group[G::DESC] !== ''): ?><p class="desc"><?= $x($group[G::DESC]) ?></p><?php endif ?>
      <?php if ($node[G::N_HAS_CHILDREN] && $node[G::N_IS_GROUP]): ?>
        <a class="also" href="<?= $h(Html::groupUrl($full)) ?>">Read <?= $h($full) ?> itself</a>
      <?php endif ?>
    </div>
    <div class="node-meta num small muted">
      <?php if ($node[G::N_HAS_CHILDREN]): ?><span><?= $h(Html::number($node[G::N_GROUPS])) ?> <?= $node[G::N_GROUPS] === 1 ? 'group' : 'groups' ?></span><?php endif ?>
      <?php if ($node[G::N_NEW] > 0): ?><span class="new"><?= $h(Html::number($node[G::N_NEW])) ?> new</span><?php endif ?>
    </div>
  </li>
<?php endforeach ?>
</ul>
