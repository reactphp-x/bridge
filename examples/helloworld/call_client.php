<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
Client::$debug = true;
$uri = 'tcp://192.168.1.9:8010';
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb', new TcpDecodeEncode);
$client->start();


$stream = $client->call(function($stream){
    // 运行在另外一个客户端
     $stream->on('data',function($data) use ($stream) {
       echo $data."\n"; // 收到hello
       $stream->end('world');
    });
    return $stream;
}, [
    'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    // ‘something’ => '10.8.0.1'
]);
$stream->write('hello');

$stream->on('data', function($data){
   echo $data."\n"; // 收到world
});

$stream->on('close', function(){
   echo "stream close\n";
});