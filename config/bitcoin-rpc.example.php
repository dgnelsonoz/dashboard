<?php
// Copy to config/bitcoin-rpc.php and configure local RPC credentials.

declare(strict_types=1);

/**
 * RPC config: dedicated rpcauth user for the web UI.
 * These should match your bitcoin.conf / rpcauth.py output.
 */
function rpc_config(): array {
    return [
        'url'      => 'http://127.0.0.1:8332/',
        'user'     => 'dashboard',
        'password' => 'RPC-PASSWORD-HERE',
        'cookie'   => null,
        'timeout'  => 5,
    ];
}
