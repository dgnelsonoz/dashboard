function setCardStatus(cardEl, status) {
  // status: "good" | "warn" | "bad" | "neutral" | "warn-pulse" | null
  if (!cardEl) return;
  cardEl.classList.remove('status-good', 'status-warn', 'status-bad', 'status-neutral', 'status-pulse');
  if (status === 'good') cardEl.classList.add('status-good');
  if (status === 'warn') cardEl.classList.add('status-warn');
  if (status === 'warn-pulse') cardEl.classList.add('status-warn', 'status-pulse');
  if (status === 'bad') cardEl.classList.add('status-bad');
  if (status === 'neutral') cardEl.classList.add('status-neutral');
}

function setConditionalCardLink(cardEl, enabled) {
  if (!cardEl) return;

  const href = cardEl.dataset.cardHref;
  if (enabled && href) {
    cardEl.setAttribute('href', href);
    cardEl.removeAttribute('aria-disabled');
    cardEl.classList.add('status-card-link');
    return;
  }

  cardEl.removeAttribute('href');
  cardEl.setAttribute('aria-disabled', 'true');
  cardEl.classList.remove('status-card-link');
}

function isStatusView() {
  // Run only on home view now
  return document.body.classList.contains('view-home');
}

function isDemoMode() {
  return document.body.classList.contains('demo-mode');
}

function loadDemoStatus() {
  const runningService = { status: 'running' };

  updateNodeStatusCard({
    service: runningService,
    rpcAvailable: true,
    initialBlockDownload: false,
    nodeType: 'Full Node',
    blocks: 908742,
    headers: 908742,
    syncPercent: 100,
    connections: 18,
    connectionsIn: 7,
    connectionsOut: 11,
    subversion: '/Satoshi:29.0.0/',
  });
  updateElectrsCard({ service: runningService, metricsAvailable: true, tipHeight: 908742, version: '0.10.9' });
  updateLndCard({ service: runningService, serviceStatus: 'running', version: '0.19.2-beta' });
  updateRtlCard({ service: runningService, httpAvailable: true, version: '0.15.4' });
  updateExplorerCard({ service: runningService, httpAvailable: true, version: '3.0.0' });
  updateMempoolCard({ service: runningService, httpAvailable: true, version: '3.2.1' });

  const mempoolCard = getMempoolCard();
  if (mempoolCard) {
    mempoolCard.dataset.cardHref = 'https://mempool.space/';
    mempoolCard.setAttribute('href', 'https://mempool.space/');
    mempoolCard.setAttribute('target', '_blank');
    mempoolCard.setAttribute('rel', 'noopener noreferrer');
  }
}

let lastNodeBlocks = null;
let lastNodeSynced = false;
let nodeRpcMisses = 0;
let lastElectrsData = null;
let electrsStatus = null;
let lastElectrsHeight = null;
let electrsMetricsMisses = 0;
let lastLndServiceStatus = null;
let lastLndVersion = null;
let lndVersionMisses = 0;
let lastMempoolVersion = null;
let lastRtlVersion = null;
let lastExplorerVersion = null;
let statusPollInFlight = false;

const NODE_RPC_MISS_THRESHOLD = 0;
const ELECTRS_METRICS_MISS_THRESHOLD = 0;
const LND_VERSION_MISS_THRESHOLD = 0;

/* -----------------------------
   Helpers
------------------------------ */

function parseCoreKnotsLabel(subversion) {
  // Examples:
  //   /Satoshi:28.2.0/
  //   /Bitcoin Knots:27.1.knots20240801/
  if (typeof subversion !== 'string' || !subversion.length) return '--';

  const isKnots = /knots/i.test(subversion);
  const impl = isKnots ? 'Knots' : 'Core';

  // Grab major.minor
  const m = subversion.match(/:(\d+\.\d+)/) || subversion.match(/(\d+\.\d+)/);
  if (!m) return '--';

  return `${impl} ${m[1]}`;
}

function computeNodePercentInt(data) {
  const blocks = (typeof data.blocks === 'number') ? data.blocks : null;
  const headers = (typeof data.headers === 'number') ? data.headers : null;
  const ibd = Boolean(data.initialBlockDownload);

  // Canonical "true 100%" rule
  if (blocks !== null && headers !== null && blocks === headers && ibd === false) {
    return 100;
  }

  // Otherwise: round to nearest integer (no decimals)
  if (typeof data.syncPercent === 'number') {
    const pct = Math.round(data.syncPercent);
    return Math.max(0, Math.min(100, pct));
  }

  // Fallback if ever needed
  if (blocks !== null && headers !== null && headers > 0) {
    const pct = Math.round((blocks / headers) * 100);
    return Math.max(0, Math.min(100, pct));
  }

  return null;
}

function computeElectrsPercentInt(tipHeight, nodeBlocks) {
  if (typeof tipHeight !== 'number' || typeof nodeBlocks !== 'number' || nodeBlocks <= 0) return null;
  if (tipHeight >= nodeBlocks) return 100;
  const pct = Math.round((tipHeight / nodeBlocks) * 100);
  return Math.max(0, Math.min(100, pct));
}

function getElectrsConnectionLabel() {
  const host = window.location.hostname || 'localhost';
  return `${host}:50001:t`;
}

function setField(field, value) {
  document.querySelectorAll(`[data-field="${field}"]`).forEach((el) => {
    el.textContent = value;
  });
}

function formatBlockHeight(value) {
  return (typeof value === 'number' && Number.isFinite(value))
    ? value.toLocaleString('en-US')
    : '--';
}

function getNodeCard() {
  return document.querySelector('.node-console-card') ||
    document.querySelector('[data-field="node-status"]')?.closest('.status-card');
}

function getElectrsCard() {
  return document.querySelector('.electrum-console-card') ||
    document.querySelector('[data-field="electrs-status-label"]')?.closest('.status-card');
}

function setElectrsIndexValue(height, percent) {
  setField('electrs-height', formatBlockHeight(height));
  setField('electrs-node-block', formatBlockHeight(lastNodeBlocks));
  setField('electrs-percent', percent);
  setField('electrs-percent-sign', percent === '' ? '' : '%');
}

function clearElectrsIndexValue() {
  setField('electrs-height', '');
  setField('electrs-node-block', '');
  setField('electrs-percent', '');
  setField('electrs-percent-sign', '');
}

function getLndCard() {
  return document.querySelector('.lnd-console-card') ||
    document.querySelector('[data-field="lnd-status"]')?.closest('.status-card');
}

function getExplorerCard() {
  return document.querySelector('.explorer-console-card') ||
    document.querySelector('[data-field="explorer-status"]')?.closest('.status-card');
}

function getMempoolCard() {
  return document.querySelector('.mempool-console-card') ||
    document.querySelector('[data-field="mempool-status"]')?.closest('.status-card');
}

function getRtlCard() {
  return document.querySelector('.rtl-console-card') ||
    document.querySelector('[data-field="rtl-status"]')?.closest('.status-card');
}

function labelize(value) {
  if (typeof value !== 'string' || value.length === 0) return 'unknown';
  return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatElectrsVersion(version) {
  return version ? `electrs v${version}` : '';
}

function formatLndVersion(version) {
  return version ? `LND v${version}` : '';
}

function formatExplorerVersion(version) {
  return version ? `v${version}` : '';
}

function formatMempoolVersion(version) {
  return version ? `v${version}` : '';
}

function formatRtlVersion(version) {
  return version ? `RTL v${version}` : '';
}

function markNodeUnavailable(label = 'Stopped', cardStatus = 'bad', version = '') {
  setField('node-status', label);
  setField('node-type', '--');
  setField('node-block', '');
  setField('node-height-separator', '');
  setField('network-block', '');
  setField('node-sync-percent', '');
  setField('node-sync-percent-sign', '');
  setField('node-connections', '');
  setField('node-connections-in', '');
  setField('node-connections-out', '');
  setField('node-version', version || '');
  lastNodeBlocks = null;
  lastNodeSynced = false;

  setCardStatus(getNodeCard(), cardStatus);

  if (lastElectrsData) {
    updateElectrsCard(lastElectrsData);
  }
}

function initShutdownDialog() {
  const shutdownButton = document.querySelector('.shutdown-button');
  const shutdownDialog = document.getElementById('shutdown-dialog');
  const confirmButton = document.querySelector('[data-shutdown-confirm]');
  const cancelButton = document.querySelector('[data-shutdown-cancel]');
  const errorMessage = document.querySelector('[data-shutdown-error]');

  if (!shutdownButton || !shutdownDialog) return;

  function setError(message) {
    if (!errorMessage) return;
    errorMessage.textContent = message;
    errorMessage.hidden = false;
  }

  function clearError() {
    if (!errorMessage) return;
    errorMessage.textContent = '';
    errorMessage.hidden = true;
  }

  function closeDialog() {
    clearError();
    if (typeof shutdownDialog.close === 'function') {
      shutdownDialog.close();
    } else {
      shutdownDialog.removeAttribute('open');
    }
  }

  shutdownButton.addEventListener('click', () => {
    clearError();
    if (typeof shutdownDialog.showModal === 'function') {
      shutdownDialog.showModal();
    } else {
      shutdownDialog.setAttribute('open', '');
    }
  });

  cancelButton?.addEventListener('click', closeDialog);
  confirmButton?.addEventListener('click', () => {
    clearError();
    confirmButton.disabled = true;
    confirmButton.textContent = 'Shutting down...';

    fetch('/api/shutdown.php', {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    })
      .then(async (res) => {
        const json = await res.json().catch(() => null);
        if (!res.ok || !json?.ok) {
          throw new Error(json?.error || 'Shutdown request failed.');
        }
        window.location.href = '/?view=shutdown';
      })
      .catch((err) => {
        setError(err.message || 'Shutdown request failed.');
        confirmButton.disabled = false;
        confirmButton.textContent = 'Yes';
      });
  });

  shutdownDialog.addEventListener('click', (event) => {
    if (event.target === shutdownDialog) {
      closeDialog();
    }
  });
}

/* -----------------------------
   Node status (bitcoind)
------------------------------ */

function updateNodeStatusCard(data) {
  const serviceStatus = data?.service?.status ?? 'unknown';

  if (serviceStatus === 'not installed') {
    nodeRpcMisses = 0;
    markNodeUnavailable('Not Installed', 'neutral');
    return;
  }

  if (serviceStatus === 'stopped') {
    nodeRpcMisses = 0;
    markNodeUnavailable('Stopped', 'bad', data?.binaryVersion ?? '');
    return;
  }

  if (serviceStatus === 'starting') {
    nodeRpcMisses = 0;
    markNodeUnavailable('Starting', 'warn', data?.binaryVersion ?? '');
    return;
  }

  if (data?.rpcAvailable === false) {
    if (serviceStatus === 'running') {
      nodeRpcMisses += 1;
      if (nodeRpcMisses < NODE_RPC_MISS_THRESHOLD && lastNodeBlocks !== null) {
        return;
      }
      // systemd is authoritative for the process state. RPC can be unavailable
      // because dashboard credentials are not configured, not only at startup.
      markNodeUnavailable('Running', 'warn', data?.binaryVersion ?? '');
    } else {
      nodeRpcMisses = 0;
      markNodeUnavailable('Stopped', 'bad', data?.binaryVersion ?? '');
    }
    return;
  }

  nodeRpcMisses = 0;
  setField('node-status', data.initialBlockDownload ? 'Synchronising' : 'Running');
  setField('node-type', data.nodeType ?? '--');

  lastNodeBlocks = (typeof data.blocks === 'number') ? data.blocks : null;
  lastNodeSynced = data.initialBlockDownload === false && lastNodeBlocks !== null;

  setField('node-block', formatBlockHeight(data.blocks));
  setField('network-block', formatBlockHeight(data.headers ?? data.blocks));

  const pctInt = computeNodePercentInt(data);
  setField('node-sync-percent', (pctInt === null ? '--' : String(pctInt)));
  setField('node-sync-percent-sign', '%');

  setField('node-connections', (typeof data.connections === 'number') ? String(data.connections) : '--');
  setField('node-connections-in', (typeof data.connectionsIn === 'number') ? String(data.connectionsIn) : '--');
  setField('node-connections-out', (typeof data.connectionsOut === 'number') ? String(data.connectionsOut) : '--');

  // Version (Core/Knots X.Y from subversion)
  setField('node-version', parseCoreKnotsLabel(data.subversion));

  const nodeCard = getNodeCard();

  if (nodeCard) {
    if (data.initialBlockDownload) {
      setCardStatus(nodeCard, 'warn-pulse');
    } else {
      setCardStatus(nodeCard, 'good');
    }
  }

  if (lastElectrsData) {
    updateElectrsCard(lastElectrsData);
  }
}

function fetchNodeStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/bitcoin-node-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('Node info error:', json.error);
        markNodeUnavailable('Stopped', 'bad');
        return;
      }
      updateNodeStatusCard(json.data);
    })
    .catch(err => {
      console.error('Node info fetch failed:', err);
      markNodeUnavailable('Stopped', 'bad');
    });
}

/* -----------------------------
   Electrs status (metrics)
------------------------------ */

function updateElectrsCard(data) {
  lastElectrsData = data;

  const serviceStatus = data?.service?.status ?? 'unknown';

  if (serviceStatus === 'not installed') {
    electrsMetricsMisses = 0;
    markElectrsNotInstalled();
    return;
  }

  if (!lastNodeSynced || typeof lastNodeBlocks !== 'number') {
    markElectrsWaitingForBitcoin(data);
    return;
  }

  if (serviceStatus === 'stopped') {
    electrsMetricsMisses = 0;
    markElectrsUnavailable('Stopped', 'bad', data);
    return;
  }

  if (serviceStatus === 'starting') {
    electrsMetricsMisses = 0;
    markElectrsUnavailable('Starting', 'warn', data);
    return;
  }

  if (data?.metricsAvailable === false) {
    if (serviceStatus === 'running') {
      electrsMetricsMisses += 1;
      if (electrsMetricsMisses < ELECTRS_METRICS_MISS_THRESHOLD && lastElectrsHeight !== null) {
        return;
      }
      markElectrsUnavailable('Starting', 'warn', data);
    } else {
      electrsMetricsMisses = 0;
      markElectrsUnavailable('Stopped', 'bad', data);
    }
    return;
  }

  electrsMetricsMisses = 0;
  lastElectrsHeight = (typeof data.tipHeight === 'number') ? data.tipHeight : lastElectrsHeight;

  setField('electrs-version', formatElectrsVersion(data.version));
  setField('electrs-connection', getElectrsConnectionLabel());

  const electrsCard = getElectrsCard();
  let nextElectrsStatus = 'warn';

  if (typeof data.tipHeight === 'number') {
    const behind = lastNodeBlocks - data.tipHeight;

    if (behind <= 0) {
      setField('electrs-status-label', 'Running');
      setField('electrs-sync-label', 'Synced');
      nextElectrsStatus = 'good';
    } else if (behind === 1) {
      setField('electrs-status-label', 'Indexing');
      setField('electrs-sync-label', 'Indexing (behind 1 block)');
      nextElectrsStatus = 'warn-pulse';
    } else {
      setField('electrs-status-label', 'Indexing');
      setField('electrs-sync-label', `Indexing (behind ${behind} blocks)`);
      nextElectrsStatus = 'warn-pulse';
    }

    const pct = computeElectrsPercentInt(data.tipHeight, lastNodeBlocks);
    setElectrsIndexValue(data.tipHeight, pct === null ? '' : String(pct));
  } else {
    setField('electrs-status-label', 'Indexing');
    setField('electrs-sync-label', 'Indexing');
    clearElectrsIndexValue();
    nextElectrsStatus = 'warn-pulse';
  }

  electrsStatus = nextElectrsStatus;
  setCardStatus(electrsCard, electrsStatus);
  applyLndCardStatus();
}

function markElectrsWaitingForBitcoin(data = null) {
  setField('electrs-status-label', 'Waiting for Bitcoin Node');
  setField('electrs-sync-label', 'Waiting for Bitcoin Node');
  clearElectrsIndexValue();
  setField('electrs-version', formatElectrsVersion(data?.version));
  setField('electrs-connection', getElectrsConnectionLabel());

  electrsStatus = 'warn';
  setCardStatus(getElectrsCard(), electrsStatus);
  applyLndCardStatus();
}

function markElectrsUnavailable(label = 'Stopped', cardStatus = 'bad', data = null) {
  setField('electrs-status-label', label);
  setField('electrs-sync-label', '--');
  clearElectrsIndexValue();
  setField('electrs-version', formatElectrsVersion(data?.version));
  setField('electrs-connection', getElectrsConnectionLabel());

  electrsStatus = cardStatus === 'warn-pulse' ? 'warn' : cardStatus;
  setCardStatus(getElectrsCard(), cardStatus);
  applyLndCardStatus();
}

function markElectrsNotInstalled() {
  setField('electrs-status-label', 'Not Installed');
  setField('electrs-sync-label', '');
  clearElectrsIndexValue();
  setField('electrs-version', '');
  setField('electrs-connection', '');

  electrsStatus = 'neutral';
  setCardStatus(getElectrsCard(), electrsStatus);
  applyLndCardStatus();
}

function markElectrsDown() {
  lastElectrsData = null;
  lastElectrsHeight = null;
  electrsMetricsMisses = 0;

  setField('electrs-status-label', 'Stopped');
  setField('electrs-sync-label', '--');
  clearElectrsIndexValue();
  setField('electrs-version', '');
  setField('electrs-connection', '');

  electrsStatus = 'bad';
  setCardStatus(getElectrsCard(), electrsStatus);
  applyLndCardStatus();
}

function fetchElectrsStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/electrum-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('Electrs info error:', json.error);
        markElectrsDown();
        return;
      }
      updateElectrsCard(json.data);
    })
    .catch(err => {
      console.error('Electrs info fetch failed:', err);
      markElectrsDown();
    });
}

/* -----------------------------
   Lightning status (LND)
------------------------------ */

function applyLndCardStatus() {
  const lndCard = getLndCard();

  if (lastLndServiceStatus !== 'running') return;

  if (!lastNodeSynced) {
    setField('lnd-status', 'Waiting for Bitcoin Node');
    setCardStatus(lndCard, 'warn');
    return;
  }

  if (electrsStatus !== 'good') {
    setField('lnd-status', 'Waiting for Electrum Server');
    setCardStatus(lndCard, 'warn');
    return;
  }

  setField('lnd-status', 'Running');
  setCardStatus(lndCard, 'good');
}

function updateLndCard(data) {
  const serviceStatus = data?.service?.status ?? data?.serviceStatus ?? 'unknown';
  lastLndServiceStatus = serviceStatus;

  if (serviceStatus === 'not installed') {
    lndVersionMisses = 0;
    lastLndVersion = null;
    setField('lnd-status', 'Not Installed');
    setField('lnd-version', '');
    setCardStatus(getLndCard(), 'neutral');
    return;
  }

  if (serviceStatus === 'stopped') {
    lndVersionMisses = 0;
    lastLndVersion = data?.version ?? null;
    setField('lnd-status', 'Stopped');
    setField('lnd-version', formatLndVersion(lastLndVersion));
    setCardStatus(getLndCard(), 'bad');
    return;
  }

  if (serviceStatus === 'starting') {
    lndVersionMisses = 0;
    lastLndVersion = data?.version ?? lastLndVersion;
    setField('lnd-status', 'Starting');
    setField('lnd-version', formatLndVersion(lastLndVersion));
    setCardStatus(getLndCard(), 'warn');
    return;
  }

  if (serviceStatus !== 'running') {
    lndVersionMisses = 0;
    setField('lnd-status', 'Stopped');
    setField('lnd-version', formatLndVersion(lastLndVersion));
    setCardStatus(getLndCard(), 'bad');
    return;
  }

  if (data?.versionAvailable === false) {
    lndVersionMisses += 1;
    if (lndVersionMisses >= LND_VERSION_MISS_THRESHOLD || lastLndVersion === null) {
      setField('lnd-status', 'Starting');
      setCardStatus(getLndCard(), 'warn');
    }
    setField('lnd-version', formatLndVersion(lastLndVersion));
    return;
  }

  lndVersionMisses = 0;
  lastLndVersion = data.version ?? lastLndVersion;
  setField('lnd-version', formatLndVersion(lastLndVersion));

  applyLndCardStatus();
}

function markLndUnavailable() {
  lastLndServiceStatus = 'unknown';
  lastLndVersion = null;
  lndVersionMisses = 0;

  setField('lnd-status', 'Stopped');
  setField('lnd-version', '');

  setCardStatus(getLndCard(), 'bad');
}

function fetchLndStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/lightning-node-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('LND info unavailable');
        markLndUnavailable();
        return;
      }
      updateLndCard(json.data ?? {});
    })
    .catch(err => {
      console.error('LND info fetch failed:', err);
      markLndUnavailable();
    });
}

/* -----------------------------
   Mempool status
------------------------------ */

function updateMempoolCard(data) {
  const serviceStatus = data?.service?.status ?? 'unknown';
  setConditionalCardLink(getMempoolCard(), !['not installed', 'unknown'].includes(serviceStatus));

  if (serviceStatus === 'not installed') {
    lastMempoolVersion = null;
    setField('mempool-status', 'Not Installed');
    setField('mempool-version', '');
    setCardStatus(getMempoolCard(), 'neutral');
    return;
  }

  lastMempoolVersion = data?.version ?? lastMempoolVersion;
  setField('mempool-version', formatMempoolVersion(lastMempoolVersion));

  if (serviceStatus === 'stopped') {
    setField('mempool-status', 'Stopped');
    setCardStatus(getMempoolCard(), 'bad');
    return;
  }

  if (serviceStatus === 'starting') {
    setField('mempool-status', 'Starting');
    setCardStatus(getMempoolCard(), 'warn');
    return;
  }

  if (serviceStatus === 'running') {
    if (data?.httpAvailable === false) {
      setField('mempool-status', 'Starting');
      setCardStatus(getMempoolCard(), 'warn');
      return;
    }

    setField('mempool-status', 'Running');
    setCardStatus(getMempoolCard(), 'good');
    return;
  }

  setField('mempool-status', 'Stopped');
  setCardStatus(getMempoolCard(), 'bad');
}

function markMempoolUnavailable() {
  lastMempoolVersion = null;
  setConditionalCardLink(getMempoolCard(), false);
  setField('mempool-status', 'Stopped');
  setField('mempool-version', '');
  setCardStatus(getMempoolCard(), 'bad');
}

function fetchMempoolStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/mempool-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('Mempool info unavailable');
        markMempoolUnavailable();
        return;
      }
      updateMempoolCard(json.data ?? {});
    })
    .catch(err => {
      console.error('Mempool info fetch failed:', err);
      markMempoolUnavailable();
    });
}

/* -----------------------------
   RTL status
------------------------------ */

function updateRtlCard(data) {
  const serviceStatus = data?.service?.status ?? 'unknown';
  setConditionalCardLink(getRtlCard(), !['not installed', 'unknown'].includes(serviceStatus));

  if (serviceStatus === 'not installed') {
    lastRtlVersion = null;
    setField('rtl-status', 'Not Installed');
    setField('rtl-version', '');
    setCardStatus(getRtlCard(), 'neutral');
    return;
  }

  lastRtlVersion = data?.version ?? lastRtlVersion;
  setField('rtl-version', formatRtlVersion(lastRtlVersion));

  if (serviceStatus === 'stopped') {
    setField('rtl-status', 'Stopped');
    setCardStatus(getRtlCard(), 'bad');
    return;
  }

  if (serviceStatus === 'starting') {
    setField('rtl-status', 'Starting');
    setCardStatus(getRtlCard(), 'warn');
    return;
  }

  if (serviceStatus === 'running') {
    if (data?.httpAvailable === false) {
      setField('rtl-status', 'Starting');
      setCardStatus(getRtlCard(), 'warn');
      return;
    }

    setField('rtl-status', 'Running');
    setCardStatus(getRtlCard(), 'good');
    return;
  }

  setField('rtl-status', 'Stopped');
  setCardStatus(getRtlCard(), 'bad');
}

function markRtlUnavailable() {
  lastRtlVersion = null;
  setConditionalCardLink(getRtlCard(), false);
  setField('rtl-status', 'Stopped');
  setField('rtl-version', '');
  setCardStatus(getRtlCard(), 'bad');
}

function fetchRtlStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/rtl-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('RTL info unavailable');
        markRtlUnavailable();
        return;
      }
      updateRtlCard(json.data ?? {});
    })
    .catch(err => {
      console.error('RTL info fetch failed:', err);
      markRtlUnavailable();
    });
}

/* -----------------------------
   BTC RPC Explorer status
------------------------------ */

function updateExplorerCard(data) {
  const serviceStatus = data?.service?.status ?? 'unknown';
  setConditionalCardLink(getExplorerCard(), !['not installed', 'unknown'].includes(serviceStatus));

  if (serviceStatus === 'not installed') {
    lastExplorerVersion = null;
    setField('explorer-status', 'Not Installed');
    setField('explorer-version', '');
    setCardStatus(getExplorerCard(), 'neutral');
    return;
  }

  lastExplorerVersion = data?.version ?? lastExplorerVersion;
  setField('explorer-version', formatExplorerVersion(lastExplorerVersion));

  if (serviceStatus === 'stopped') {
    setField('explorer-status', 'Stopped');
    setCardStatus(getExplorerCard(), 'bad');
    return;
  }

  if (serviceStatus === 'starting') {
    setField('explorer-status', 'Starting');
    setCardStatus(getExplorerCard(), 'warn');
    return;
  }

  if (serviceStatus === 'running') {
    if (data?.httpAvailable === false) {
      setField('explorer-status', 'Starting');
      setCardStatus(getExplorerCard(), 'warn');
      return;
    }

    setField('explorer-status', 'Running');
    setCardStatus(getExplorerCard(), 'good');
    return;
  }

  setField('explorer-status', 'Stopped');
  setCardStatus(getExplorerCard(), 'bad');
}

function markExplorerUnavailable() {
  lastExplorerVersion = null;
  setConditionalCardLink(getExplorerCard(), false);
  setField('explorer-status', 'Stopped');
  setField('explorer-version', '');
  setCardStatus(getExplorerCard(), 'bad');
}

function fetchExplorerStatus() {
  if (!isStatusView()) return Promise.resolve();

  return fetch('/api/explorer-status.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) {
        console.warn('Explorer info unavailable');
        markExplorerUnavailable();
        return;
      }
      updateExplorerCard(json.data ?? {});
    })
    .catch(err => {
      console.error('Explorer info fetch failed:', err);
      markExplorerUnavailable();
    });
}

/* -----------------------------
   Boot
------------------------------ */

document.addEventListener('DOMContentLoaded', () => {
  initShutdownDialog();

  if (isStatusView() && isDemoMode()) {
    loadDemoStatus();
    return;
  }

  function pollDashboardStatus() {
    if (!isStatusView() || statusPollInFlight) return;

    statusPollInFlight = true;
    Promise.allSettled([
      fetchNodeStatus(),
      fetchElectrsStatus(),
      fetchLndStatus(),
      fetchMempoolStatus(),
      fetchRtlStatus(),
      fetchExplorerStatus(),
    ]).finally(() => {
      statusPollInFlight = false;
    });
  }

  pollDashboardStatus();

  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;

    // A page restored from the browser's back/forward cache does not fire
    // DOMContentLoaded again. Clear any poll that was frozen by navigation and
    // refresh immediately instead of waiting for the next interval.
    statusPollInFlight = false;
    pollDashboardStatus();
  });

  setInterval(() => {
    pollDashboardStatus();
  }, 5000);
});
