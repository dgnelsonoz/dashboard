<?php
declare(strict_types=1);

function respond(bool $ok, mixed $data = null, ?string $error = null, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode([
        'ok'    => $ok,
        'data'  => $data,
        'error' => $error,
    ]);
    exit;
}
