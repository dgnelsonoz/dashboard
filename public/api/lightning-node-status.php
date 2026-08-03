<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/lib/api-response.php';

const SYSTEMCTL_BIN = '/usr/bin/systemctl';
const LND_BIN_CANDIDATES = [
    '/usr/local/bin/lnd',
    '/usr/bin/lnd',
    'lnd',
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

function get_lnd_service_status(): array {
    $show = run_local_command(
        [SYSTEMCTL_BIN, 'show', 'lnd.service', '--property=LoadState', '--property=ActiveState', '--no-pager'],
        2
    );

    if ($show['exitCode'] !== 0 || $show['stdout'] === '') {
        return [
            'name' => 'lnd.service',
            'loadState' => 'unknown',
            'activeState' => 'unknown',
            'status' => 'unknown',
        ];
    }

    $props = parse_systemctl_properties($show['stdout']);

    if (($props['LoadState'] ?? '') === 'not-found') {
        return [
            'name' => 'lnd.service',
            'loadState' => 'not-found',
            'activeState' => 'unknown',
            'status' => 'not installed',
        ];
    }

    $activeState = $props['ActiveState'] ?? 'unknown';
    return [
        'name' => 'lnd.service',
        'loadState' => $props['LoadState'] ?? 'unknown',
        'activeState' => $activeState,
        'status' => $activeState === 'active'
            ? 'running'
            : ($activeState === 'activating' ? 'starting' : 'stopped'),
    ];
}

function parse_lnd_version(string $output): ?string {
    if (preg_match('/\bv?([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-[A-Za-z0-9.]+)?)\b/', $output, $m)) {
        return $m[1];
    }

    return null;
}

function get_lnd_version(): ?string {
    foreach (LND_BIN_CANDIDATES as $bin) {
        $result = run_local_command([$bin, '--version'], 3);
        if ($result['exitCode'] !== 0 || $result['stdout'] === '') {
            continue;
        }

        $version = parse_lnd_version($result['stdout']);
        if ($version !== null) {
            return $version;
        }
    }

    return null;
}

$serviceStatus = get_lnd_service_status();
$version = ($serviceStatus['status'] === 'not installed') ? null : get_lnd_version();

respond(true, [
    'service' => $serviceStatus,
    'serviceStatus' => $serviceStatus['status'],
    'versionAvailable' => is_string($version) && $version !== '',
    'version' => $version,
]);
