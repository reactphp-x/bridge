<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\WebsocketBridge;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;

use React\Http\HttpServer;
use React\Socket\SocketServer;
use Reactphp\Framework\WebsocketMiddleware\WebsocketMiddleware;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\async;
use React\Http\Message\Response;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb'
]));
$websocketBridge = new WebsocketBridge($server);
$http = new HttpServer(new WebsocketMiddleware($websocketBridge));
$socket = new SocketServer('0.0.0.0:8010');
$http->listen($socket);

$pool = new Pool($server, [
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

Loop::addPeriodicTimer(2, function () use ($pool) {
    echo "getPoolCount({$pool->getPoolCount()})\n";
    echo "getWaitQueueCount({$pool->getWaitCount()})\n";
    echo "getIdleCount({$pool->idleConnectionCount()})\n";
});

$socket = new SocketServer('0.0.0.0:8090');
$socket->on('connection', async(function (\React\Socket\ConnectionInterface $connection) use ($pool) {



    return;

    $data = '';
    $connection->on('data', $fn = function ($buffer) use (&$data) {
        $data .= $buffer;
    });

    $stream = $pool->call(function ($stream) {
        $data = '';
        $stream->on('data', $fn = function ($buffer) use (&$data) {
            $buffer = preg_replace('/Host: ' . '192.168.1.9:8090' . '.*\r\n/', "Host: 192.168.1.9:8080\r\n", $buffer);
            $data .= $buffer;
        });
        $connector = new \React\Socket\Connector();
        $connector->connect('192.168.1.9:8080')->then(function (\React\Socket\ConnectionInterface $connection) use (&$data, $stream, $fn) {
            if ($data) {
                $connection->write($data);
                $data = '';
            }
            $stream->removeListener('data', $fn);
            $stream->pipe($connection, [
                'end' => true
            ]);
            $connection->pipe($stream, [
                'end' => true
            ]);
            $connection->on('close', function () use ($stream) {
                $stream->end();
            });
            $stream->on('close', function () use ($connection) {
                $connection->end();
            });
        }, function (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        return $stream;
    }, [
        'uuid' =>'8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
    ]);

    $connection->removeListener('data', $fn);
    $fn = null;

    if ($data) {
        $stream->write($data);
        $data = '';
    }

    $connection->pipe($stream, [
        'end' => true
    ]);
    $stream->pipe($connection, [
        'end' => true
    ]);
    $stream->on('close', function () use ($connection) {
        $connection->close();
    });
    $connection->on('close', function () use ($stream) {
        $stream->end();
    });
}));
