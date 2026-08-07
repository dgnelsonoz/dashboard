<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
dashboard_reject_demo_api();

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/lib/api-response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Shutdown must be requested with POST.', 405);
}

$socketPath = '/run/shutdownd.sock';
$socketUri = 'unix://' . $socketPath;
$errno = 0;
$errstr = '';

if (!file_exists($socketPath)) {
    respond(false, null, 'Shutdown button is not enabled', 503);
}

$socket = @stream_socket_client($socketUri, $errno, $errstr, 2, STREAM_CLIENT_CONNECT);

if ($socket === false) {
    if (!file_exists($socketPath)) {
        respond(false, null, 'Shutdown button is not enabled', 503);
    }

    $detail = $errstr !== '' ? $errstr : 'unknown socket error';
    respond(false, null, "Could not connect to shutdownd at {$socketPath}: {$detail}", 503);
}

stream_set_timeout($socket, 3);

$bytesWritten = fwrite($socket, "shutdown\n");
if ($bytesWritten === false || $bytesWritten < strlen("shutdown\n")) {
    fclose($socket);
    respond(false, null, 'Could not send shutdown request to shutdownd.', 502);
}

$response = fgets($socket);
$metadata = stream_get_meta_data($socket);
fclose($socket);

if ($response === false) {
    $message = !empty($metadata['timed_out'])
        ? 'Timed out waiting for shutdownd response.'
        : 'No response received from shutdownd.';
    respond(false, null, $message, 502);
}

$responseText = trim($response);

if ($responseText !== 'OK') {
    respond(false, null, "shutdownd rejected the request: {$responseText}", 502);
}

respond(true, ['status' => 'OK']);
