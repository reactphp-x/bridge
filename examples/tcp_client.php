<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
Client::$debug = true;

$uri = 'tcp://192.168.1.9:8010';
$client = new Client($uri, '8d24e2ba-c6f8-4bb6-8838-cacd37f64165', new TcpDecodeEncode);
$client->start();
