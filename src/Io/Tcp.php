<?php

namespace Reactphp\Framework\Bridge\Io;

// use Reactphp\Framework\Bridge\Interface\ServerInterface;
use Reactphp\Framework\Bridge\Interface\MessageComponentInterface;
use React\Socket\SocketServer;

class Tcp
{

    protected $server;
    protected $socket;

    public function __construct($uri, MessageComponentInterface $server, $context = [])
    {
        $this->server = $server;
        $socket = new SocketServer($uri, $context);
        $socket->on('connection', function ($conn) {
            $this->server->onOpen($conn, [
                'remote_address' => $conn->getRemoteAddress(),
                'local_address' =>  $conn->getLocalAddress(),
            ]);
            $conn->on('data', function ($data) use ($conn) {
                $this->handleData($conn, $data);
            });
            $conn->on('close', function () use ($conn) {
                $this->handleClose($conn);
            });
            $conn->on('error', function ($e) use ($conn) {
                $this->handleError($conn, $e);
            });
        });
        $this->socket = $socket;
    }

    protected function handleData($conn, $data)
    {
        try {
            $this->server->onMessage($conn, $data);
        } catch (\Exception $e) {
            $this->server->onError($conn, $e);
        }
    }

    protected function handleClose($conn)
    {
        try {
            $this->server->onClose($conn);
        } catch (\Exception $e) {
            $this->server->onError($conn, $e);
        }
    }

    protected function handleError($conn, $e)
    {
        $this->server->onError($conn, $e);
    }

    public function close()
    {
        $this->socket->close();
    }
}
