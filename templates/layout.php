<?php
/** @var callable $h @var callable $x @var string $title @var string $description @var string $content @var string $mainSite */
$main = rtrim($mainSite, '/');
?>
<!DOCTYPE html>
<html lang="en" data-skin="raza">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $x($title) ?> · News · NETWORK XXIII</title>
<meta name="description" content="<?= $x($description) ?>">
<meta name="theme-color" content="#cd2127">
<link rel="icon" href="<?= $h($main) ?>/networkxxiii-23.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $h($main) ?>/site.css">
<link rel="stylesheet" href="/app.css">
</head>
<body>
<a class="skip-content" href="#main">Skip to content</a>
<header class="top">
  <div class="wrap">
    <a class="brand" href="<?= $h($main) ?>/" aria-label="NETWORK XXIII home">
      <img src="<?= $h($main) ?>/networkxxiii-landscape.svg" alt="NETWORK XXIII" width="150" height="48">
    </a>
    <nav class="main news-nav" aria-label="News">
      <a href="/">Newsgroups</a>
      <a href="/search">Search</a>
    </nav>
  </div>
</header>

<main id="main">
<?php if (isset($staleAge)): ?>
<p class="wrap stale-note" role="status">You are seeing a saved copy from <?= $h($staleAge < 120 ? $staleAge . ' seconds' : intdiv($staleAge, 60) . ' minutes') ?> ago, because the news server is busy or unavailable.</p>
<?php endif ?>
<?= $content ?>
</main>

<footer>
  <div class="wrap">
    <div class="fine">
      <span>Part of <a href="<?= $h($main) ?>/">NETWORK XXIII</a> · Channel 4 · Red sector</span>
      <span>Usenet via news.networkxxiii.de · addresses are partly hidden</span>
    </div>
  </div>
</footer>
</body>
</html>
