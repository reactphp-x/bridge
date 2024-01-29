<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../porttoport.php';

use Reactphp\Framework\Bridge\Client;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;

Client::$debug = true;

$uri = $argv[3] ?? 'tcp://192.168.1.9:8010';
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb', new TcpDecodeEncode);
$client->start();

// client[63306]->server->client[127.0.0.1:3306"]
(new PortToPort($client))
    ->from($argv[1] ?? '63306', function ($data) {
        return $data;
    })
    ->to(
        '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
        ($argv[2] ?? '127.0.0.1:3306'),
        function ($data) {
            return $data;
        }
    )->start();
