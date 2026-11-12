<?php
use Newsstand\Web\Html;
/** @var callable $h @var list<string> $groups @var list<string> $moderated */
?>
<section class="wrap page-head">
  <div class="eyebrow">On air</div>
  <h1>Your article was sent</h1>
  <?php if ($moderated !== []): ?>
    <p class="lead"><?= $h(implode(', ', $moderated)) ?> <?= count($moderated) === 1 ? 'is' : 'are' ?> moderated, so the article goes to the moderator first and appears once it is approved.</p>
  <?php else: ?>
    <p class="lead">It usually shows up in the thread list within a minute or two.</p>
  <?php endif ?>
  <p class="actions">
    <?php foreach ($groups as $g): ?>
      <a class="btn" href="<?= $h(Html::groupUrl($g)) ?>">Go to <?= $h($g) ?></a>
    <?php endforeach ?>
  </p>
</section>
