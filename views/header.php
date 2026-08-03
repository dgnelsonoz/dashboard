<header class="site-header">
  <div class="site-header-inner">
    <div class="site-logo">
      <span class="site-logo-text">Node Dashboard</span>
    </div>
    <div class="site-header-controls">
      <nav class="site-nav">
        <a href="/" class="nav-link<?php echo ($view === 'home') ? ' nav-link-active' : ''; ?>">Home</a>
        <a href="/?view=rtl" class="nav-link<?php echo ($view === 'rtl') ? ' nav-link-active' : ''; ?>">Lightning</a>
        <a href="/?view=explorer" class="nav-link<?php echo ($view === 'explorer') ? ' nav-link-active' : ''; ?>">Explorer</a>
        <a href="/?view=mempool" class="nav-link<?php echo ($view === 'mempool') ? ' nav-link-active' : ''; ?>">Mempool</a>
        <a href="/?view=guides" class="nav-link<?php echo ($view === 'guides') ? ' nav-link-active' : ''; ?>">Guides</a>
      </nav>
      <button class="shutdown-button" type="button" aria-label="Shutdown">
        <span class="shutdown-button-icon" aria-hidden="true">&#9211;</span>
        <span class="shutdown-button-label">Shutdown</span>
      </button>
    </div>
  </div>
</header>

<dialog class="shutdown-dialog" id="shutdown-dialog" aria-labelledby="shutdown-dialog-title">
  <div class="shutdown-dialog-body">
    <h2 id="shutdown-dialog-title">Shutdown the node?</h2>
    <p class="shutdown-dialog-error" data-shutdown-error hidden></p>
    <div class="shutdown-dialog-actions">
      <button class="dialog-button dialog-button-primary" type="button" data-shutdown-confirm>Yes</button>
      <button class="dialog-button" type="button" data-shutdown-cancel>Cancel</button>
    </div>
  </div>
</dialog>
