<?php

declare(strict_types=1);

// Serves a self-signed certificate on an ephemeral loopback port for a fixed number of
// connections, printing the port first. Used to exercise the SSL check against a real handshake.
[$script, $certificatePath, $connections, $mode] = $argv;

$context = stream_context_create(['ssl' => [
    'local_cert' => $certificatePath,
    'verify_peer' => false,
    'verify_peer_name' => false,
]]);
$server = stream_socket_server(($mode === 'stall' ? 'tcp' : 'ssl') . '://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, (string) $errorMessage);

    return;
}

echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), PHP_EOL;
fflush(STDOUT);

for ($served = 0; $served < (int) $connections; $served++) {
    $client = @stream_socket_accept($server, 10);

    if (is_resource($client)) {
        echo 'accepted', PHP_EOL;
        fflush(STDOUT);

        if ($mode === 'stall') {
            // Accept TCP and consume the ClientHello without ever sending TLS bytes back.
            stream_set_timeout($client, 10);
            stream_get_contents($client);
        }

        @fclose($client);
    }
}
