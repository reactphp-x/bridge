<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;
use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\WebsocketBridge;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Reactphp\Framework\WebsocketMiddleware\WebsocketMiddleware;
use function React\Async\async;
use React\EventLoop\Loop;

Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd'
    // '10.10.10.1',
    // '10.10.10.2',
    // '10.10.10.3',
]));



$pool = new Pool($server, [
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

Loop::addPeriodicTimer(1, function () use ($pool) {
    echo "getPoolCount({$pool->getPoolCount()})\n";
    echo "getWaitQueueCount({$pool->getWaitCount()})\n";
    echo "getIdleCount({$pool->idleConnectionCount()})\n";
});


$http = new HttpServer(new WebsocketMiddleware(new WebsocketBridge($server)));
$socket = new SocketServer('0.0.0.0:8010');
$http->listen($socket);

