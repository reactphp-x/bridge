<?php

require __DIR__ . '/../../vendor/autoload.php';

use ReactphpX\Bridge\Server;
use ReactphpX\Bridge\Pool;
use ReactphpX\Bridge\Verify\VerifyUuid;
use ReactphpX\Bridge\Http\HttpBridge;
use ReactphpX\Bridge\WebSocket\WsBridge;
use ReactphpX\Bridge\Tcp\TcpBridge;
use ReactphpX\Bridge\BridgeStrategy;
use ReactphpX\Bridge\Io\Tcp;

// Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165' => '10.10.10.1',//value 是自定义的标识符，可以是空
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb' => '10.10.10.2',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd' => '10.10.10.3'
]));

$server->enableKeepAlive(5);

$pool = new Pool($server, [
    'min_connections' => 1,
    'max_connections' => 20,
    'connection_timeout' => 2,
    'uuid_max_tunnel' => 1,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

new Tcp('0.0.0.0:' . ($argv[1] ?? '8010'), new BridgeStrategy([
    new TcpBridge($server),
    new HttpBridge(new WsBridge($server))
]));



use ReactphpX\Bridge\Business\StreamToPort;


$socket = new React\Socket\SocketServer('0.0.0.0:8090');

$socket->on('connection', function (React\Socket\ConnectionInterface $connection) use ($pool) {
    StreamToPort::create($pool)
                ->from(null, '', $connection)
                ->to('8d24e2ba-c6f8-4bb6-8838-cacd37f64165', 'unix:///tmp/same.sock', $outMapBuffer = null, $toSecretKey = null)
                ->start();
});

return $pool;
