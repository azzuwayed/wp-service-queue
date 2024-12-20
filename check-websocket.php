<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

function check_websocket_server()
{
    echo "Checking if WebSocket server is running...\n";
    $connection = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
    if (is_resource($connection)) {
        fclose($connection);
        echo "WebSocket server is already running\n";
        return true;
    }
    echo "WebSocket server is not running\n";
    return false;
}

function start_websocket_server()
{
    if (!check_websocket_server()) {
        $websocket_script = __DIR__ . '/websocket-server.php';
        $log_file = __DIR__ . '/websocket.log';
        $error_log = __DIR__ . '/websocket-error.log';

        echo "Starting WebSocket server...\n";
        echo "WebSocket script path: $websocket_script\n";
        echo "Log file path: $log_file\n";
        echo "Error log path: $error_log\n";

        $command = 'php "' . $websocket_script . '"';
        if (PHP_OS === 'WINNT') {
            echo "Executing Windows command...\n";
            $result = pclose(popen('start /B cmd /C "' . $command . ' > ' . $log_file . ' 2>' . $error_log . '"', 'r'));
            echo "Command execution result: " . ($result === 0 ? "Success" : "Failed") . "\n";

            sleep(2);

            if (!check_websocket_server()) {
                echo "Failed to start WebSocket server. Check logs for details.\n";
                if (file_exists($log_file)) {
                    echo "\nWebSocket log contents:\n";
                    echo file_get_contents($log_file) . "\n";
                }
                if (file_exists($error_log)) {
                    echo "\nError log contents:\n";
                    echo file_get_contents($error_log) . "\n";
                }
            }
        } else {
            exec($command . ' > ' . $log_file . ' 2>' . $error_log . ' & echo $!');
        }
    }
}

start_websocket_server();
