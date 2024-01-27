<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use function React\Async\async;
use function React\Async\await;
use React\EventLoop\Loop;

Client::$debug = true;
$uri = 'ws://192.168.1.9:8010';
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb');
$client->start();

async(function () use ($client) {

    $stream = $client->call(function ($stream, $info) {
        return [
            'local_address' => $info['local_address'],
            'remote_address' => $info['remote_address'],
        ];
    }, [
        'uuid' => 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb'
    ]);
    
    $stream->on('data', function ($data) {
        var_dump('self', $data);
    });
    
    $stream->on('close', function () {
        echo "close\n";
    });
    
    $stream->on('error', function ($e) {
        echo $e->getMessage() . "\n";
    });
    
    
    $peerStream = $client->call(function ($stream, $info) {

        return [
            'local_address' => $info['local_address'],
            'remote_address' => $info['remote_address'],
        ];
    }, [
        'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
    ]);
    
    $peerStream->on('data', function ($data) {
        var_dump('peer', $data);
    });
    
    $peerStream->on('close', function () {
        echo "close\n";
    });
    
    $peerStream->on('error', function ($e) {
        echo $e->getMessage() . "\n";
    });
})();



