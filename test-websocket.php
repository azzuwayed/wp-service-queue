<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/websocket-error.log');

echo "Starting test WebSocket server...\n";

// Check if required extensions are loaded
$required_extensions = ['openssl', 'sockets', 'mbstring'];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        die("Required PHP extension '{$ext}' is not loaded.\n");
    }
}

require_once(__DIR__ . '/vendor/autoload.php');

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class TestWebSocket implements MessageComponentInterface
{
    public function onOpen(ConnectionInterface $conn)
    {
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo "Received message: $msg\n";
    }

    public function onClose(ConnectionInterface $conn)
    {
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

try {
    echo "Setting up server...\n";
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new TestWebSocket()
            )
        ),
        8080,
        '127.0.0.1'
    );

    echo "Server running on ws://127.0.0.1:8080\n";
    $server->run();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
