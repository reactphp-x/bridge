<?php

require __DIR__ . '/../../vendor/autoload.php';

$client = require __DIR__ . '/../../client.php';


$stream = $client->call(function($stream){
    // 运行在另外一个客户端
     $stream->on('data',function($data) use ($stream) {
       echo $data."\n"; // 收到hello
       $stream->end('world');
    });
    return $stream;
}, [
    'uuid' => $argv[3] ?? '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    // ‘something’ => '10.8.0.1'
]);
$stream->write('hello');

$stream->on('data', function($data){
   echo $data."\n"; // 收到world
});

$stream->on('close', function(){
   echo "stream close\n";
});