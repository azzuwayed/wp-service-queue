<?php
// start-websocket.php
define('WEBSOCKET_LOG_FILE', __DIR__ . '/websocket.log');

// Check if WebSocket server is already running
function isWebSocketServerRunning($port = 8080)
{
    $connection = @fsockopen('127.0.0.1', $port);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

// Start WebSocket server if not running
if (!isWebSocketServerRunning()) {
    $command = 'php ' . __DIR__ . '/websocket-server.php';
    if (PHP_OS === 'WINNT') {
        pclose(popen('start /B ' . $command, 'r'));
    } else {
        exec($command . ' > ' . WEBSOCKET_LOG_FILE . ' 2>&1 & echo $!');
    }
}
