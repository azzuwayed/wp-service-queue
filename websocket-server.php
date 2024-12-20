<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/websocket-error.log');

require_once(__DIR__ . '/vendor/autoload.php');

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// Check if required extensions are loaded
$required_extensions = ['openssl', 'sockets', 'mbstring'];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        die("Required PHP extension '{$ext}' is not loaded.\n");
    }
}

// Verify the port is available
$test_socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($test_socket === false) {
    die("Failed to create test socket: " . socket_strerror(socket_last_error()) . "\n");
}

if (@socket_bind($test_socket, '127.0.0.1', 8080) === false) {
    die("Port 8080 is already in use or not available.\n");
}
socket_close($test_socket);

class ServiceQueueWebSocket implements MessageComponentInterface
{
    protected $clients;
    protected $subscriptions;
    protected $loop;
    protected $logger;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->subscriptions = [];
        $this->loop = Loop::get();
        $this->initLogger();

        // Log startup
        $this->log("WebSocket server initialized");
    }

    protected function initLogger()
    {
        $logFile = __DIR__ . '/websocket.log';
        $this->logger = function ($message) use ($logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents(
                $logFile,
                "[$timestamp] $message" . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        };
    }

    protected function log($message)
    {
        ($this->logger)($message);
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->log("New connection! ({$conn->resourceId})");

        // Send initial connection success message
        $conn->send(json_encode([
            'type' => 'connection_established',
            'resourceId' => $conn->resourceId,
            'message' => 'Successfully connected to WebSocket server'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->log("Received message from {$from->resourceId}: $msg");

        try {
            $data = json_decode($msg, true);
            if (!$data) {
                throw new \Exception('Invalid JSON message');
            }

            switch ($data['action'] ?? '') {
                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;

                case 'broadcast':
                    $this->handleBroadcast($data);
                    break;

                case 'ping':
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => time()
                    ]));
                    break;

                default:
                    $this->log("Unknown action received: " . ($data['action'] ?? 'no action specified'));
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Unknown action'
                    ]));
            }
        } catch (\Exception $e) {
            $this->log("Error handling message: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }

    protected function handleSubscribe(ConnectionInterface $conn, array $data)
    {
        $queueId = $data['queue_id'] ?? 'global';
        $this->subscriptions[$conn->resourceId] = $queueId;

        $conn->send(json_encode([
            'type' => 'subscription_confirmed',
            'queue_id' => $queueId,
            'message' => 'Successfully subscribed to queue'
        ]));

        $this->log("Client {$conn->resourceId} subscribed to queue: $queueId");
    }

    protected function handleBroadcast(array $data)
    {
        $message = json_encode([
            'type' => 'service_update',
            'data' => $data['data'] ?? null,
            'timestamp' => time()
        ]);

        $recipientCount = 0;
        foreach ($this->clients as $client) {
            $queueId = $this->subscriptions[$client->resourceId] ?? null;
            if ($queueId === 'global' || $queueId === ($data['queue_id'] ?? 'global')) {
                $client->send($message);
                $recipientCount++;
            }
        }

        $this->log("Broadcasted update to $recipientCount clients");
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        unset($this->subscriptions[$conn->resourceId]);
        $this->log("Connection {$conn->resourceId} has disconnected");
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->log("Error occurred for connection {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }
}

// Check if the server is already running
function isPortInUse($port)
{
    $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

// Server configuration
$port = 8080;
$host = '127.0.0.1';

try {
    if (isPortInUse($port)) {
        throw new \Exception("Port $port is already in use");
    }

    echo "Starting WebSocket server on ws://$host:$port" . PHP_EOL;

    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new ServiceQueueWebSocket()
            )
        ),
        $port,
        $host
    );

    echo "WebSocket server running. Press Ctrl-C to quit." . PHP_EOL;

    $server->run();
} catch (\Exception $e) {
    $errorMessage = "Failed to start WebSocket server: " . $e->getMessage();
    error_log($errorMessage);
    echo $errorMessage . PHP_EOL;
    exit(1);
}
