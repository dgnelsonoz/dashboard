<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bitcoin-rpc.php';

/**
 * Helper: call bitcoind RPC
 * Returns: ['result' => ...] on success OR ['error' => '...'] on failure
 */
function rpc_call(string $method, array $params = []): array {
    $cfg = rpc_config();
    $url  = $cfg['url'];
    $user = $cfg['user'];
    $pass = $cfg['password'];
    $cookiePath = $cfg['cookie'] ?? null;

    $ch = curl_init($url);
    $payload = json_encode([
        'jsonrpc' => '1.0',
        'id'      => 'node-ui',
        'method'  => $method,
        'params'  => $params,
    ]);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
    ];

    if (is_string($cookiePath) && $cookiePath !== '') {
        if (!is_readable($cookiePath)) {
            return ['error' => 'RPC cookie file is not readable: ' . $cookiePath];
        }
        $options[CURLOPT_USERPWD] = trim((string)file_get_contents($cookiePath));
    } else {
        $options[CURLOPT_USERPWD] = $user . ':' . $pass;
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Curl error: ' . $err];
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON from bitcoind (HTTP ' . $code . '): ' . substr((string)$raw, 0, 200)];
    }

    if (isset($decoded['error']) && $decoded['error'] !== null) {
        return ['error' => 'RPC error: ' . json_encode($decoded['error'])];
    }

    return ['result' => $decoded['result']];
}
