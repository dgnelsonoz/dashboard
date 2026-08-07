<?php
$demoServices = [
  'rtl' => ['image' => 'lightning.png', 'title' => 'Ride The Lightning'],
  'explorer' => ['image' => 'explorer.png', 'title' => 'BTC RPC Explorer'],
  'mempool' => ['image' => 'mempool.png', 'title' => 'Mempool'],
];
$demoService = $demoServices[$view];
?>
<section class="demo-service">
  <div class="demo-service-banner">Demo Only &mdash; Not to Scale</div>
  <img
    class="demo-service-image"
    src="/assets/demo/<?php echo htmlspecialchars($demoService['image'], ENT_QUOTES); ?>"
    alt="Static preview of <?php echo htmlspecialchars($demoService['title'], ENT_QUOTES); ?>"
  >
</section>
