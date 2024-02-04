<?php

namespace Reactphp\Framework\Bridge\Tcp;

use Reactphp\Framework\Bridge\Interface\MessageComponentInterface;
use React\Stream\DuplexStreamInterface;

class TcpBridge implements MessageComponentInterface
{

    protected $component;

    public function __construct(MessageComponentInterface $component)
    {
        $this->component = $component;
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $this->component->onOpen($stream, $info);
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        $this->component->onClose($stream, $reason);
    }

    public function onError(DuplexStreamInterface $conn, \Exception $e)
    {
        $this->component->onError($conn, $e);
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        $this->component->onMessage($stream, $msg);
    }
}