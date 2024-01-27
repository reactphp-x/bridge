<?php

namespace Reactphp\Framework\Bridge;

use Reactphp\Framework\Bridge\Interface\ServerInterface;
use React\Socket\SocketServer;

class TcpBridge{

    protected $server;

    public function __construct($uri, ServerInterface $server, $context = [])
    {
        $this->server = $server;
        $socket = new SocketServer($uri, $context);
        $socket->on('connection', function ($conn) {
            $this->server->onOpen($conn, [
                'remote_address' => $conn->getRemoteAddress(),
            ]);
            $conn->on('data', function ($data) use ($conn) {
                $this->server->onMessage($conn, $data);
            });
            $conn->on('close', function () use ($conn) {
                $this->server->onClose($conn);
            });
            $conn->on('error', function ($e) use ($conn) {
                $this->server->onError($conn, $e);
                $conn->close();
            });
        });
    }
}