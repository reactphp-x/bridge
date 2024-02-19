<?php


use React\Promise\Deferred;

$client = require __DIR__ . '/../client.php';
// \Reactphp\Framework\Bridge\Client::$secretKey = '';
$deferred = new Deferred();

$self = [];
$peer = [];

$stream = $client->call(function ($stream, $info) {
    return [
        'local_address' => $info['local_address'],
        'remote_address' => $info['remote_address'],
    ];
}, [
    'uuid' => $argv[2] ?? 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb'
]);

$stream->on('data', function ($data) use (&$self, &$peer, $deferred) {
    if ($peer) {
        $deferred->resolve(['self' => $self, 'peer' => $peer]);
    } else {
        $self = $data;
    }
    // var_dump('self', $data);
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
    'uuid' => $argv[3] ?? '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
]);

$peerStream->on('data', function ($data) use (&$self, &$peer, $deferred) {
    if ($self) {
        $deferred->resolve(['self' => $self, 'peer' => $data]);
    } else {
        $peer = $data;
    }
    // var_dump('peer', $data);
});

$peerStream->on('close', function () {
    echo "close\n";
});

$peerStream->on('error', function ($e) {
    echo $e->getMessage() . "\n";
});

$deferred->promise()->then(function ($data) use ($stream, $peerStream) {
    var_dump($data);
    $stream->end();
    $peerStream->end();
});