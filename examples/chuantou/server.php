<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
use Reactphp\Framework\Bridge\TcpBridge;
use Reactphp\Framework\Bridge\Business\PortToPort;
use React\EventLoop\Loop;

Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165' => '10.10.10.1',
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb' => '10.10.10.2',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd' => '10.10.10.3'
]), new TcpDecodeEncode);



$pool = new Pool($server, [
    'max_connections' => 20,
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);


$tcp = new TcpBridge('0.0.0.0:8010', $server);



PortToPort::create($pool)
->from(null, '8090', function($buffer){
    $buffer = preg_replace('/Host: ' . '192.168.1.9:8090' . '.*\r\n/', "Host: 192.168.1.9:8080\r\n", $buffer);
    return $buffer;
})
->to(
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    '127.0.0.1:8080',
    null,
)->start();