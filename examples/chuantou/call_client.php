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
    $stream = $client->call(function ($stream) {
        // return 'hello world';
        $i = 0;
        $timer = Loop::addPeriodicTimer(1, function () use ($stream, &$i) {
            $i++;
            echo "hello {$i}\n";
            $stream->write("hello {$i}\n");
        });

        Loop::addTimer(10, function () use ($stream, $timer) {
            Loop::cancelTimer($timer);
            $stream->end();
        });

        return $stream;
    }, [
        'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
    ]);
    
    $stream->on('data', function ($data) {
        echo $data;
    });
    
    $stream->on('close', function () {
        echo "close\n";
    });
    
    $stream->on('error', function ($e) {
        echo $e->getMessage() . "\n";
    });
})();



