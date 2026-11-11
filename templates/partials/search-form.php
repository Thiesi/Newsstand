<?php /** @var callable $h @var string $q */ ?>
<form class="group-search" action="/search" method="get" role="search">
  <label for="q">Find a newsgroup</label>
  <div class="row">
    <input id="q" name="q" type="search" value="<?= $h($q ?? '') ?>" placeholder="e.g. php, de.rec.fahrrad, astronomy" maxlength="100" autocomplete="off">
    <button class="btn primary" type="submit">Search</button>
  </div>
  <p class="hint muted small">Searches group names and descriptions. All words must match.</p>
</form>
