<?php
require_once dirname(__DIR__, 2) . '/config/app.php';
dashboard_reject_demo_api();

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/lib/api-response.php';

const SYSTEMCTL_BIN = '/usr/bin/systemctl';
const BITCOIN_SERVICE_CANDIDATES = [
    'bitcoind.service',
    'bitcoin.service',
    'bitcoin-core.service',
];
const BITCOIND_BIN_CANDIDATES = [
    '/usr/local/bin/bitcoind',
    '/usr/bin/bitcoind',
    'bitcoind',
];
const SYSTEMD_UNIT_DIRECTORIES = [
    '/etc/systemd/system',
    '/run/systemd/system',
    '/usr/local/lib/systemd/system',
    '/usr/lib/systemd/system',
    '/lib/systemd/system',
];

function run_local_command(array $cmd, int $timeoutSeconds = 3): array {
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return [
            'exitCode' => null,
            'stdout' => '',
            'stderr' => '',
            'timedOut' => false,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if ((time() - $start) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }

        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exitCode' => $timedOut ? null : $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'timedOut' => $timedOut,
    ];
}

function parse_systemctl_properties(string $stdout): array {
    $props = [];
    foreach (explode("\n", $stdout) as $line) {
        $parts = explode('=', trim($line), 2);
        if (count($parts) === 2) {
            $props[$parts[0]] = $parts[1];
        }
    }
    return $props;
}

function bitcoin_service_unit_exists(): bool {
    foreach (SYSTEMD_UNIT_DIRECTORIES as $directory) {
        foreach (BITCOIN_SERVICE_CANDIDATES as $serviceName) {
            if (file_exists($directory . '/' . $serviceName)) {
                return true;
            }
        }
    }

    return false;
}

function get_bitcoin_service_status(): array {
    $systemctlResponded = false;

    foreach (BITCOIN_SERVICE_CANDIDATES as $serviceName) {
        $show = run_local_command(
            [SYSTEMCTL_BIN, 'show', $serviceName, '--property=LoadState', '--property=ActiveState', '--no-pager'],
            2
        );

        if ($show['exitCode'] !== 0 || $show['stdout'] === '') {
            continue;
        }

        $systemctlResponded = true;
        $props = parse_systemctl_properties($show['stdout']);
        if (($props['LoadState'] ?? '') === 'not-found') {
            continue;
        }

        $activeState = $props['ActiveState'] ?? 'unknown';
        return [
            'name' => $serviceName,
            'loadState' => $props['LoadState'] ?? 'unknown',
            'activeState' => $activeState,
            'status' => $activeState === 'active'
                ? 'running'
                : ($activeState === 'activating' ? 'starting' : 'stopped'),
        ];
    }

    if (!$systemctlResponded && bitcoin_service_unit_exists()) {
        return [
            'name' => null,
            'loadState' => 'unknown',
            'activeState' => 'unknown',
            'status' => 'unknown',
        ];
    }

    return [
        'name' => null,
        'loadState' => 'not-found',
        'activeState' => 'unknown',
        'status' => 'not installed',
    ];
}

function get_bitcoin_binary_version(): ?string {
    foreach (BITCOIND_BIN_CANDIDATES as $bin) {
        $result = run_local_command([$bin, '-datadir=/tmp', '-version'], 3);
        if ($result['exitCode'] !== 0 || $result['stdout'] === '') {
            continue;
        }

        if (preg_match('/Bitcoin (Core|Knots).*?version v?([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $result['stdout'], $m)) {
            return $m[1] . ' ' . $m[2];
        }

        if (preg_match('/\bv?([0-9]+\.[0-9]+(?:\.[0-9]+)?)\b/', $result['stdout'], $m)) {
            return 'Core ' . $m[1];
        }
    }

    return null;
}

$service = get_bitcoin_service_status();
if ($service['status'] === 'not installed') {
    respond(true, [
        'service' => $service,
        'rpcAvailable' => false,
    ]);
}

if ($service['status'] === 'stopped') {
    respond(true, [
        'service' => $service,
        'rpcAvailable' => false,
        'binaryVersion' => get_bitcoin_binary_version(),
    ]);
}

if ($service['status'] === 'starting') {
    respond(true, [
        'service' => $service,
        'rpcAvailable' => false,
        'binaryVersion' => get_bitcoin_binary_version(),
    ]);
}

// Keep reporting the systemd state when the private RPC configuration has not
// been created yet. A missing config must not turn an active service into an
// HTTP 500, which the dashboard would otherwise present as "Stopped".
$rpcConfigFile = dirname(__DIR__, 2) . '/config/bitcoin-rpc.php';
if (!is_file($rpcConfigFile)) {
    respond(true, [
        'service' => $service,
        'rpcAvailable' => false,
        'rpcError' => 'Bitcoin RPC is not configured for the dashboard.',
        'binaryVersion' => get_bitcoin_binary_version(),
    ]);
}

require_once dirname(__DIR__, 2) . '/lib/bitcoin-rpc-client.php';

// 1) Blockchain info
$bc = rpc_call('getblockchaininfo');
if (!isset($bc['result'])) {
    respond(true, [
        'service' => $service,
        'rpcAvailable' => false,
        'rpcError' => $bc['error'] ?? 'Failed to getblockchaininfo',
        'binaryVersion' => $service['status'] === 'running' ? get_bitcoin_binary_version() : null,
    ]);
}
$bcinfo = $bc['result'];

// 2) Uptime (seconds) – optional nice-to-have
$up = rpc_call('uptime');
$uptimeSeconds = isset($up['result']) ? (int)$up['result'] : null;

// 3) Network info – for connection count + version string
$net = rpc_call('getnetworkinfo');
$connections = isset($net['result']['connections'])
    ? (int)$net['result']['connections']
    : null;

$connectionsIn = isset($net['result']['connections_in'])
    ? (int)$net['result']['connections_in']
    : null;

$connectionsOut = isset($net['result']['connections_out'])
    ? (int)$net['result']['connections_out']
    : null;

$subversion = isset($net['result']['subversion'])
    ? (string)$net['result']['subversion']
    : null;

// Compute sync percentage based on verificationprogress or blocks/headers
$blocks  = (int)($bcinfo['blocks'] ?? 0);
$headers = isset($bcinfo['headers']) ? (int)$bcinfo['headers'] : $blocks;
$chain   = $bcinfo['chain'] ?? 'unknown';

if (isset($bcinfo['verificationprogress'])) {
    $syncPercent = round(((float)$bcinfo['verificationprogress']) * 100, 2);
} else {
    $syncPercent = ($headers > 0)
        ? round(($blocks / $headers) * 100, 2)
        : 0.0;
}

// Pretty uptime string
$uptimeHuman = null;
if ($uptimeSeconds !== null) {
    $days = intdiv($uptimeSeconds, 86400);
    $rem  = $uptimeSeconds % 86400;
    $hours = intdiv($rem, 3600);
    $rem   = $rem % 3600;
    $mins  = intdiv($rem, 60);

    $parts = [];
    if ($days > 0)  $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($mins > 0 || empty($parts)) $parts[] = $mins . 'm';
    $uptimeHuman = implode(' ', $parts);
}

respond(true, [
    'service'               => $service,
    'rpcAvailable'          => true,
    'chain'                 => $chain,
    'blocks'                => $blocks,
    'headers'               => $headers,
    'syncPercent'           => $syncPercent,
    'verificationProgress'  => $bcinfo['verificationprogress'] ?? null,
    'initialBlockDownload'  => $bcinfo['initialblockdownload'] ?? null,
    'nodeType'              => !empty($bcinfo['pruned']) ? 'Pruned' : 'Full',
    'warnings'              => $bcinfo['warnings'] ?? null,
    'uptimeSeconds'         => $uptimeSeconds,
    'uptimeHuman'           => $uptimeHuman,
    'connections'           => $connections,
    'connectionsIn'         => $connectionsIn,
    'connectionsOut'        => $connectionsOut,
    'subversion'            => $subversion,
]);
