<?php
declare(strict_types=1);

function dashboard_mode(): string {
    $mode = getenv('DASHBOARD_MODE');

    return is_string($mode) && strtolower(trim($mode)) === 'demo' ? 'demo' : 'live';
}

function dashboard_is_demo(): bool {
    return dashboard_mode() === 'demo';
}

function dashboard_reject_demo_api(): void {
    if (!dashboard_is_demo()) {
        return;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => false,
        'data' => null,
        'error' => 'This endpoint is unavailable in demo mode.',
    ]);
    exit;
}
