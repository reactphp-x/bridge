<?php

namespace ReactphpX\Bridge\Tcp;

use React\Stream\DuplexStreamInterface;
use ReactphpX\Bridge\Interface\MessageComponentInterface;

class TcpBridge implements TcpBridgeInterface
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