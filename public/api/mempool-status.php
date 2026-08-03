<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/lib/api-response.php';
require_once dirname(__DIR__, 2) . '/config/services.php';

const SYSTEMCTL_BIN = '/usr/bin/systemctl';
const MEMPOOL_SERVICE_CANDIDATES = [
    'mempool.service',
];
const MEMPOOL_PACKAGE_CANDIDATES = [
    '/usr/local/lib/mempool/frontend/package.json',
    '/usr/local/lib/mempool/backend/package.json',
    '/usr/local/src/mempool/mempool-3.3.1/frontend/package.json',
    '/usr/local/src/mempool/mempool-3.3.1/backend/package.json',
    '/usr/local/src/mempool/mempool-3.2.1/frontend/package.json',
    '/usr/local/src/mempool/mempool-3.2.1/backend/package.json',
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

function get_mempool_service_status(): array {
    $systemctlResponded = false;

    foreach (MEMPOOL_SERVICE_CANDIDATES as $serviceName) {
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

function get_mempool_version(): ?string {
    foreach (MEMPOOL_PACKAGE_CANDIDATES as $packageFile) {
        if (!is_readable($packageFile)) {
            continue;
        }

        $package = json_decode((string)file_get_contents($packageFile), true);
        if (is_array($package) && isset($package['version']) && is_string($package['version'])) {
            return $package['version'];
        }
    }

    return null;
}

function mempool_http_available(): bool {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);

    $headers = @get_headers(dashboard_local_service_url('mempool'), false, $ctx);
    if (!is_array($headers) || !isset($headers[0])) {
        return false;
    }

    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$headers[0], $m)) {
        $statusCode = (int)$m[1];
        return $statusCode >= 100 && $statusCode < 500;
    }

    return false;
}

$service = get_mempool_service_status();
$version = ($service['status'] === 'not installed') ? null : get_mempool_version();
$httpAvailable = $service['status'] === 'running' ? mempool_http_available() : false;

respond(true, [
    'service' => $service,
    'httpAvailable' => $httpAvailable,
    'versionAvailable' => is_string($version) && $version !== '',
    'version' => $version,
]);
