<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;

Client::$debug = true;
$uri = 'ws://192.168.1.9:8010';
$client = new Client($uri, '8d24e2ba-c6f8-4bb6-8838-cacd37f64165');
$client->start();
