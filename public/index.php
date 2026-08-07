<?php
require_once dirname(__DIR__) . '/config/app.php';

$view = $_GET['view'] ?? 'home';
$views = ['home', 'explorer', 'mempool', 'rtl', 'guides', 'shutdown'];
if (!in_array($view, $views, true)) {
  $view = 'home';
}

$isDemo = dashboard_is_demo();

if ($isDemo && $view === 'mempool') {
  header('Location: https://mempool.space/', true, 302);
  exit;
}

$embeddedViews = $isDemo ? [] : ['explorer', 'mempool', 'rtl'];
$isEmbeddedView = in_array($view, $embeddedViews, true);
$iframeUrl = null;

if ($isDemo && in_array($view, ['explorer', 'rtl'], true)) {
  $contentView = 'demo-service.php';
} elseif ($isEmbeddedView) {
  require_once dirname(__DIR__) . '/config/services.php';

  $hostHeader = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
  $host = preg_replace('/:\d+$/', '', $hostHeader);
  $iframeUrl = dashboard_public_service_url($view, $host);
}

$guides = [];
$guideKey = '';
$guide = null;

if ($view === 'guides') {
  require_once dirname(__DIR__) . '/lib/guides.php';
  require_once dirname(__DIR__) . '/lib/markdown.php';

  $guidesDir = dirname(__DIR__) . '/guides';
  $guideNavigationPath = $guidesDir . '/guide-navigation.md';

  foreach (dashboard_guide_sections($guideNavigationPath) as $slug) {
    $file = $guidesDir . '/' . $slug . '.md';
    $markdown = is_readable($file) ? (string)file_get_contents($file) : '';
    $guides[$slug] = [
      'title' => dashboard_markdown_title($markdown, ucwords(str_replace('-', ' ', $slug))),
      'file' => $file,
    ];
  }

  $guideKey = $_GET['guide'] ?? (array_key_first($guides) ?? '');
  if (!isset($guides[$guideKey])) {
    $guideKey = array_key_first($guides) ?? '';
  }
  $guide = $guides[$guideKey] ?? null;
}

$isDemoServiceView = $isDemo && in_array($view, ['explorer', 'rtl'], true);
$mainClass = $view === 'home' ? '' : ($isDemoServiceView ? 'demo-service-view' : $view . '-view');
$showFooter = !$isEmbeddedView;

if ($isEmbeddedView) {
  $contentView = 'embedded-service.php';
} elseif ($view === 'shutdown') {
  $contentView = 'shutdown.php';
} elseif ($view === 'guides') {
  $contentView = 'guides.php';
} else {
  $contentView = 'home.php';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Bitcoin Node</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="/assets/styles.css?v=46">
  <link rel="shortcut icon" href="/favicon.ico?v=5">
  <link rel="icon" href="/favicon.ico?v=5" type="image/x-icon" sizes="16x16">
  <link rel="icon" href="/assets/icons/favicon-16x16.png?v=5" type="image/png" sizes="16x16">
  <link rel="icon" href="/assets/icons/favicon-32x32.png?v=5" type="image/png" sizes="32x32">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=5" sizes="180x180">
  <script src="/assets/app.js?v=33" defer></script>
</head>

<body class="app-body<?php echo ' view-' . htmlspecialchars($view, ENT_QUOTES); ?><?php echo $isDemo ? ' demo-mode' : ''; ?>">
  <?php include dirname(__DIR__) . '/views/header.php'; ?>

  <main class="site-main<?php echo $mainClass !== '' ? ' ' . htmlspecialchars($mainClass, ENT_QUOTES) : ''; ?>">
    <?php include dirname(__DIR__) . '/views/' . $contentView; ?>
  </main>

  <?php if ($showFooter): ?>
    <?php include dirname(__DIR__) . '/views/footer.php'; ?>
  <?php endif; ?>
</body>
</html>
