<?php

namespace Reactphp\Framework\Bridge\Business\ServerStream;

use Evenement\EventEmitterTrait;
use React\Socket\SocketServer;
use React\EventLoop\LoopInterface;

class TcpServerStream extends AbstractServerStream
{
    use EventEmitterTrait;

    protected $socket;


    public function start()
    {
        if (!$this->status) {
            $this->status = true;
            $this->socket = new SocketServer($this->uri, $this->context, $this->loop);
            $this->socket->on('connection', function ($connection) {
                $this->emit('stream', [$connection, [
                    'remote_address' => $connection->getRemoteAddress(),
                    'local_address' => $connection->getLocalAddress(),
                ]]);
            });
            $this->socket->on('error', function ($error) {
                $this->emit('error', [$error]);
            });
            $this->socket->on('close', function () {
                $this->emit('close');
            });
        }
    }

    public function stop()
    {
        if ($this->status) {
            $this->socket->close();
            $this->socket = null;
        }
    }
}
