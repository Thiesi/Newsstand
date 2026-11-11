<?php
use Newsstand\Text\Mailbox;
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var View $view @var ThreadNode $node @var string $group @var int $depth */
$e = $node->entry;
?>
<div class="post<?= $depth === 0 ? ' root' : '' ?>">
<?php if ($e === null): ?>
  <span class="muted"><?= $depth === 0 && ($first = $node->firstEntry()) !== null ? $x($first->subject) . ' · ' : '' ?>(earlier article not on this page)</span>
<?php else: $ts = $e->timestamp(); ?>
  <a class="subj" href="<?= $h(Html::articleUrl($group, $e->number)) ?>"><?= $x($e->subject !== '' ? $e->subject : '(no subject)') ?></a>
  <span class="by small"><?= $x(Mailbox::parse($e->from)->label()) ?></span>
  <time class="num small muted" datetime="<?= $h(Html::isoDate($ts)) ?>"><?= $h(Html::date($ts)) ?></time>
<?php endif ?>
</div>
<?php if ($node->children !== []): ?>
<ul class="replies<?= $depth >= 6 ? ' flat' : '' ?>">
  <?php foreach ($node->children as $child): ?>
    <li><?= $view->render('partials/thread-node', ['node' => $child, 'group' => $group, 'depth' => $depth + 1]) ?></li>
  <?php endforeach ?>
</ul>
<?php endif ?>
