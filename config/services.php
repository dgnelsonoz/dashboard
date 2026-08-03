<?php
declare(strict_types=1);

function dashboard_service_config(): array {
    return [
        'explorer' => [
            'port' => 4082,
        ],
        'mempool' => [
            'port' => 4081,
        ],
        'rtl' => [
            'port' => 4083,
        ],
    ];
}

function dashboard_service_port(string $service): int {
    $config = dashboard_service_config();
    return (int)($config[$service]['port'] ?? 0);
}

function dashboard_public_service_url(string $service, string $defaultHost): string {
    $port = dashboard_service_port($service);

    return "http://{$defaultHost}:{$port}/";
}

function dashboard_local_service_url(string $service): string {
    $port = dashboard_service_port($service);

    return "http://127.0.0.1:{$port}/";
}
