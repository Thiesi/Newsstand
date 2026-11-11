<?php /** @var callable $h @var string $title @var string $message @var int $status */ ?>
<section class="wrap page-head">
  <div class="eyebrow num">Error <?= $h($status) ?></div>
  <h1><?= $h($title) ?></h1>
  <p class="lead"><?= $h($message) ?></p>
  <p><a class="btn" href="/">Go to the newsgroup list</a></p>
</section>
