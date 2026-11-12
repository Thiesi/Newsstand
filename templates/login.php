<?php /** @var callable $h @var string $next @var string $token @var ?string $error @var string $user */ ?>
<section class="wrap page-head form-page">
  <div class="eyebrow">Log in</div>
  <h1>Log in to post</h1>
  <p class="lead">Use your mailbox address and password, the same login as for the news server. Once logged in, you see full addresses and can post.</p>

  <?php if ($error !== null): ?>
    <p class="form-error" role="alert"><?= $h($error) ?></p>
  <?php endif ?>

  <form class="form" method="post" action="/login">
    <input type="hidden" name="token" value="<?= $h($token) ?>">
    <input type="hidden" name="next" value="<?= $h($next) ?>">
    <div class="field">
      <label for="user">Mailbox address</label>
      <input id="user" name="user" type="email" autocomplete="username" required maxlength="254" value="<?= $h($user) ?>" spellcheck="false" autocapitalize="off">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
    </div>
    <div class="actions">
      <button class="btn primary" type="submit">Log in</button>
    </div>
  </form>
  <p class="muted small">Your password is passed to the news server to check it and kept, encrypted, only until you log out or are idle for two hours. The key to it is in your browser's cookie, not on our server.</p>
</section>
