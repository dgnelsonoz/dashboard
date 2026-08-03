<?php
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/lib/api-response.php';

const SYSTEMCTL_BIN = '/usr/bin/systemctl';
const ELECTRS_SERVICE_CANDIDATES = [
    'electrs.service',
    'electrum.service',
    'electrum-server.service',
];

/**
 * Where Electrs exposes Prometheus metrics.
 * Change this if your electrs config uses a different listen address/port.
 *
 * Common values:
 * - http://127.0.0.1:4224/metrics
 * - http://127.0.0.1:3004/metrics
 */
$METRICS_URL = 'http://127.0.0.1:4224/metrics';

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

function get_electrs_service_status(): array {
    $systemctlResponded = false;

    foreach (ELECTRS_SERVICE_CANDIDATES as $serviceName) {
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

    if (!$systemctlResponded) {
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

function http_get_text($url) {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 2,
            'header'  => "Accept: text/plain\r\n",
        ]
    ]);
    $text = @file_get_contents($url, false, $ctx);
    return $text === false ? null : $text;
}

function extract_electrs_height($metricsText) {
    // Electrs metric names can vary by version/build.
    // We'll look for any "electrs_*height" gauge and take the largest.
    //
    // Examples you might see:
    //   electrs_tip_height 933641
    //   electrs_index_height 933641
    //   electrs_indexed_height 933641
    //
    // We'll accept:
    //   electrs...height{...} 933641
    //   electrs...height 933641
    $best = null;

    foreach (explode("\n", $metricsText) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // Match: name{labels} value   OR   name value
        if (preg_match('/^(electrs[^\s{]*height)(\{[^}]*\})?\s+([0-9]+)(\.[0-9]+)?$/', $line, $m)) {
            $val = (int)$m[3];
            if ($best === null || $val > $best) $best = $val;
        }
    }

    return $best;
}

function get_electrs_version() {
    // No hardcoding: ask the local binary.
    // Try common install paths.
    $candidates = [
        '/usr/local/bin/electrs',
        '/usr/bin/electrs',
        'electrs',
    ];

    foreach ($candidates as $bin) {
        // Escape just in case.
        $cmd = escapeshellcmd($bin) . ' --version 2>/dev/null';
        $out = @shell_exec($cmd);
        if (!is_string($out)) continue;

        $out = trim($out);
        if ($out === '') continue;

        // Typical output examples:
        // "electrs 0.10.9"
        // "electrs 0.9.14"
        if (preg_match('/\bv?([0-9]+\.[0-9]+(\.[0-9]+)?)\b/', $out, $m)) {
            return $m[1];
        }
    }

    return null;
}

$service = get_electrs_service_status();
if ($service['status'] === 'not installed') {
    respond(true, [
        'service' => $service,
        'metricsAvailable' => false,
    ]);
}

if ($service['status'] === 'stopped') {
    respond(true, [
        'service' => $service,
        'metricsAvailable' => false,
        'version' => get_electrs_version(),
    ]);
}

if ($service['status'] === 'starting') {
    respond(true, [
        'service' => $service,
        'metricsAvailable' => false,
        'version' => get_electrs_version(),
    ]);
}

$metrics = http_get_text($METRICS_URL);
if ($metrics === null) {
    respond(true, [
        'service' => $service,
        'metricsAvailable' => false,
        'metricsError' => "Failed to fetch electrs metrics from {$METRICS_URL}",
        'version' => get_electrs_version(),
    ]);
}

$height = extract_electrs_height($metrics);
if (!is_int($height)) {
    respond(true, [
        'service' => $service,
        'metricsAvailable' => false,
        'metricsError' => 'Electrs metrics fetched but height not found',
        'version' => get_electrs_version(),
    ]);
}

$version = get_electrs_version();

respond(true, [
    'service' => $service,
    'metricsAvailable' => true,
    'tipHeight' => $height,
    'version' => $version,
]);
