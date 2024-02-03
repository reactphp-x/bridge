<?php

namespace Reactphp\Framework\Bridge;


use Psr\Http\Message\ServerRequestInterface;
use Reactphp\Framework\WebsocketMiddleware\ConnectionInterface;
use Reactphp\Framework\WebsocketMiddleware\MessageComponentInterface;
use Reactphp\Framework\Bridge\Interface\MessageComponentInterface as ServerMessageComponentInterface;

class WebsocketBridge implements MessageComponentInterface
{

    protected $server;

    public function __construct(ServerMessageComponentInterface $server)
    {
        $this->server = $server;
    }

    public function onOpen(ConnectionInterface $conn, ServerRequestInterface $request)
    {
        $remoteAddress = '';
        if (isset($request->getServerParams()['REMOTE_ADDR'])){
            $remoteAddress = $request->getServerParams()['REMOTE_ADDR'].':'.$request->getServerParams()['REMOTE_PORT'];
        }

        $this->server->onOpen($conn->getStream(), [
            'remote_address' => $remoteAddress,
        ]);

    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->server->onMessage($from->getStream(), $msg);
    }

    public function onClose(ConnectionInterface $conn, $reason = null)
    {
        $this->server->onClose($conn->getStream(), $reason);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->server->onError($conn->getStream(), $e);
        $conn->close();
    }
}