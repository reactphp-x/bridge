<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../porttoport.php';

use Reactphp\Framework\Bridge\Client;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
use function React\Async\async;
use React\Socket\SocketServer;
use React\Socket\Connector;
Client::$debug = true;

$uri = 'tcp://192.168.1.9:8010';
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb', new TcpDecodeEncode);
$client->start();

// client[8022]->server->client[127.0.0.1:22]
(new PortToPort($client))
->from('8022')
->to(
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    '127.0.0.1:22'
)->start();
