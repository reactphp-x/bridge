<?php

use Reactphp\Framework\Bridge\Business\PortToPort;

$client = require __DIR__ . '/../client.php';

// client[63306]->server->client[127.0.0.1:3306"]
(new PortToPort($client))
    ->from(null, $argv[3] ?? '63306', function ($data) {
        return $data;
    })
    ->to(
        $argv[4] ?? '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
        $argv[5] ?? '127.0.0.1:3306',
        function ($data) {
            return $data;
        }
    )->start();
