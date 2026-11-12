<?php
use Newsstand\Web\Html;
/** @var callable $h @var callable $x @var array<string, string> $form @var array<string, string> $errors @var list<string> $notes @var list<string> $moderated @var int $maxGroups @var ?string $csrf @var ?string $currentUser */
$isReply = $form['reply'] !== '';
$err = static fn (string $f): string => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="err-' . $f . '"' : '';
?>
<section class="wrap page-head form-page">
  <nav class="crumbs" aria-label="Breadcrumbs">
    <a href="/">All</a> <span aria-hidden="true">›</span>
    <?php if (\Newsstand\Nntp\GroupName::isValid($form['group'])): ?>
      <a href="<?= $h(Html::groupUrl($form['group'])) ?>"><?= $h($form['group']) ?></a> <span aria-hidden="true">›</span>
    <?php endif ?>
    <span aria-current="page"><?= $isReply ? 'Reply' : 'New thread' ?></span>
  </nav>
  <h1><?= $isReply ? 'Write a reply' : 'Start a new thread' ?></h1>

  <?php if (isset($errors['form'])): ?>
    <p class="form-error" role="alert"><?= $h($errors['form']) ?></p>
  <?php elseif ($errors !== []): ?>
    <p class="form-error" role="alert">Nothing was posted yet. Please check the fields marked below.</p>
  <?php endif ?>
  <?php foreach ($notes as $note): ?><p class="notice"><?= $h($note) ?></p><?php endforeach ?>
  <?php if ($moderated !== []): ?>
    <p class="notice"><span class="pill standby">Moderated</span> <?= $h(implode(', ', $moderated)) ?> <?= count($moderated) === 1 ? 'is' : 'are' ?> moderated: your post goes to the moderator first and appears once approved.</p>
  <?php endif ?>

  <form class="form compose" method="post" action="/post">
    <input type="hidden" name="csrf" value="<?= $h((string) $csrf) ?>">
    <input type="hidden" name="group" value="<?= $h($form['group']) ?>">
    <input type="hidden" name="reply" value="<?= $h($form['reply']) ?>">

    <div class="field">
      <label for="from-name">Your name <span class="muted small">(optional)</span></label>
      <input id="from-name" name="name" type="text" maxlength="64" autocomplete="name" value="<?= $h($form['name']) ?>">
      <p class="hint muted small">Shown as the author together with your address, <?= $h((string) $currentUser) ?>.</p>
    </div>

    <div class="field">
      <label for="newsgroups">Newsgroups</label>
      <input id="newsgroups" name="newsgroups" type="text" required spellcheck="false" autocapitalize="off" value="<?= $h($form['newsgroups']) ?>"<?= $err('newsgroups') ?>>
      <?php if (isset($errors['newsgroups'])): ?><p class="field-error" id="err-newsgroups"><?= $h($errors['newsgroups']) ?></p><?php endif ?>
      <p class="hint muted small">Separate groups with commas, at most <?= $h($maxGroups) ?>.</p>
    </div>

    <div class="field">
      <label for="followup_to">Follow-ups to <span class="muted small">(optional)</span></label>
      <input id="followup_to" name="followup_to" type="text" spellcheck="false" autocapitalize="off" value="<?= $h($form['followup_to']) ?>"<?= $err('followup_to') ?>>
      <?php if (isset($errors['followup_to'])): ?><p class="field-error" id="err-followup_to"><?= $h($errors['followup_to']) ?></p><?php endif ?>
      <p class="hint muted small">Where replies should go, if not to the groups above.</p>
    </div>

    <div class="field">
      <label for="subject">Subject</label>
      <input id="subject" name="subject" type="text" required maxlength="200" value="<?= $x($form['subject']) ?>"<?= $err('subject') ?>>
      <?php if (isset($errors['subject'])): ?><p class="field-error" id="err-subject"><?= $h($errors['subject']) ?></p><?php endif ?>
    </div>

    <div class="field">
      <label for="body">Text</label>
      <textarea id="body" name="body" rows="18" cols="72" required spellcheck="true"<?= $err('body') ?>><?= $x($form['body']) ?></textarea>
      <?php if (isset($errors['body'])): ?><p class="field-error" id="err-body"><?= $h($errors['body']) ?></p><?php endif ?>
      <p class="hint muted small">Plain text. Lines are wrapped at 72 characters when posted. Trim the quote to what you are answering.</p>
    </div>

    <div class="actions">
      <button class="btn primary" type="submit">Post</button>
      <?php if (\Newsstand\Nntp\GroupName::isValid($form['group'])): ?>
        <a class="btn" href="<?= $h(Html::groupUrl($form['group'])) ?>">Cancel</a>
      <?php endif ?>
    </div>
  </form>
</section>
