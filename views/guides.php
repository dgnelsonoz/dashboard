<section class="panel guides-panel">
  <div class="panel-header">
    <h2>Guides</h2>
  </div>

  <div class="guides-layout">
    <aside class="guides-nav" aria-label="Guides navigation">
      <ul>
        <?php foreach ($guides as $key => $g): ?>
          <li<?php echo ($key === $guideKey) ? ' class="active"' : ''; ?>>
            <a href="/?view=guides&amp;guide=<?php echo htmlspecialchars($key, ENT_QUOTES); ?>">
              <?php echo htmlspecialchars($g['title'], ENT_QUOTES); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <article class="guides-content">
      <?php if ($guide !== null && is_readable($guide['file'])): ?>
        <?php echo dashboard_markdown_to_html((string)file_get_contents($guide['file'])); ?>
      <?php elseif ($guide !== null): ?>
        <h1><?php echo htmlspecialchars($guide['title'], ENT_QUOTES); ?></h1>
        <p>Guide file missing: <code><?php echo htmlspecialchars(basename($guide['file']), ENT_QUOTES); ?></code></p>
      <?php else: ?>
        <h1>Guides</h1>
        <p>No guides are configured in <code>guides/guide-navigation.md</code>.</p>
      <?php endif; ?>
    </article>
  </div>
</section>
