<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;
use Reactphp\Framework\Bridge\Http\HttpBridge;
use Reactphp\Framework\Bridge\WebSocket\WsBridge;
use Reactphp\Framework\Bridge\Tcp\TcpBridge;
use Reactphp\Framework\Bridge\BridgeStrategy;
use Reactphp\Framework\Bridge\Io\Tcp;

Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165' => '10.10.10.1',//value 是自定义的标识符，可以是空
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb' => '10.10.10.2',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd' => '10.10.10.3'
]));

$pool = new Pool($server, [
    'max_connections' => 20,
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

new Tcp('0.0.0.0:' . ($argv[1] ?? '8010'), new BridgeStrategy([
    new TcpBridge($server),
    new HttpBridge(new WsBridge($server))
]));

return $pool;
