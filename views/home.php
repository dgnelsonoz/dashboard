<!-- Home dashboard -->
<section class="cards cards-status">

  <!-- Bitcoin Node console card -->
  <article class="card status-card node-console-card">
    <h2><span class="status-led" aria-hidden="true"></span>Bitcoin Node</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="node-status">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">TYPE</div>
        <div class="kv-v"><span data-field="node-type">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">HEIGHT</div>
        <div class="kv-v"><span data-field="node-block">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">TIP</div>
        <div class="kv-v"><span data-field="network-block">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">SYNC</div>
        <div class="kv-v"><span data-field="node-sync-percent">--</span><span data-field="node-sync-percent-sign">%</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Peers</div>
        <div class="kv-v"><span data-field="node-connections">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">IN</div>
        <div class="kv-v"><span data-field="node-connections-in">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">OUT</div>
        <div class="kv-v"><span data-field="node-connections-out">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="node-version">--</span></div>
      </div>
    </div>
  </article>

  <!-- Electrum Server (Electrs) console card -->
  <article class="card status-card electrum-console-card" data-full-node-only hidden>
    <h2><span class="status-led" aria-hidden="true"></span>Electrum Server</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="electrs-status-label">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">INDEX</div>
        <div class="kv-v"><span data-field="electrs-height"></span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">NODE</div>
        <div class="kv-v"><span data-field="electrs-node-block"></span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">SYNC</div>
        <div class="kv-v"><span data-field="electrs-percent"></span><span data-field="electrs-percent-sign"></span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="electrs-version"></span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Connection</div>
        <div class="kv-v"><span data-field="electrs-connection">--</span></div>
      </div>
    </div>
  </article>

  <!-- Lightning (LND) console card -->
  <article class="card status-card lnd-console-card" data-full-node-only hidden>
    <h2><span class="status-led" aria-hidden="true"></span>Lightning Node</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="lnd-status">unknown</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="lnd-version"></span></div>
      </div>
    </div>
  </article>

  <!-- Ride The Lightning (RTL) console card -->
  <a
    class="card status-card rtl-console-card"
    data-full-node-only
    hidden
    data-card-href="/?view=rtl"
    aria-label="Open Ride The Lightning"
    aria-disabled="true"
  >
    <h2><span class="status-led" aria-hidden="true"></span>Ride The Lightning</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="rtl-status">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="rtl-version"></span></div>
      </div>
    </div>
  </a>

  <!-- BTC RPC Explorer console card -->
  <a
    class="card status-card explorer-console-card"
    data-card-href="/?view=explorer"
    aria-label="Open BTC RPC Explorer"
    aria-disabled="true"
  >
    <h2><span class="status-led" aria-hidden="true"></span>BTC RPC Explorer</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="explorer-status">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="explorer-version"></span></div>
      </div>
    </div>
  </a>

  <!-- Mempool console card -->
  <a
    class="card status-card mempool-console-card"
    data-full-node-only
    hidden
    data-card-href="/?view=mempool"
    aria-label="Open Mempool"
    aria-disabled="true"
  >
    <h2><span class="status-led" aria-hidden="true"></span>Mempool</h2>

    <div class="kv-rows" role="list">
      <div class="kv-row" role="listitem">
        <div class="kv-k">Status</div>
        <div class="kv-v"><span data-field="mempool-status">--</span></div>
      </div>

      <div class="kv-row" role="listitem">
        <div class="kv-k">Version</div>
        <div class="kv-v"><span data-field="mempool-version"></span></div>
      </div>
    </div>
  </a>

</section>
