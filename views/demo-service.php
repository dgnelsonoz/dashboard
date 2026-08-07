<?php
$demoService = $view === 'rtl' ? 'lightning' : 'explorer';
$demoTitle = $view === 'rtl' ? 'Ride The Lightning' : 'BTC RPC Explorer';
?>
<section class="demo-service" aria-labelledby="demo-service-title">
  <div class="demo-service-heading">
    <p class="demo-label">Interactive demo preview</p>
    <h1 id="demo-service-title"><?php echo htmlspecialchars($demoTitle, ENT_QUOTES); ?></h1>
  </div>
  <img
    class="demo-service-image"
    src="/assets/demo/<?php echo htmlspecialchars($demoService, ENT_QUOTES); ?>.svg"
    alt="Static preview of <?php echo htmlspecialchars($demoTitle, ENT_QUOTES); ?>"
  >
</section>
