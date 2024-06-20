<?php

require __DIR__ . '/../../vendor/autoload.php';

use React\Socket\UnixServer;
use React\EventLoop\Loop;

$client = require __DIR__ . '/../client.php';

$unixSock = $argv[3] ?? '/tmp/same.sock';


$http = new React\Http\HttpServer(function (Psr\Http\Message\ServerRequestInterface $request) {
    return React\Http\Message\Response::plaintext(
        "Hello World!\n"
    );
});

$socket = new UnixServer($unixSock);

$http->listen($socket);

Loop::addSignal(\defined('SIGINT') ? \SIGINT : 2, $f1 = static function () use ($socket, $unixSock): void {
    if (\PHP_VERSION_ID >= 70200 && \stream_isatty(\STDIN)) {
        echo "\r";
    }
    echo "Received SIGINT, stopping loop\n";
    if (\file_exists($unixSock)) {
        \unlink($unixSock);
    }
    $socket->close();
    Loop::stop();
});
Loop::addSignal(\defined('SIGTERM') ? \SIGTERM : 15, $f2 = static function () use ($socket, $unixSock): void {
    $socket->close();
    echo "Received SIGTERM, stopping loop\n";
    if (\file_exists($unixSock)) {
        \unlink($unixSock);
    }
    Loop::stop();
});
