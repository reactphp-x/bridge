<?php

namespace Reactphp\Framework\Bridge\Tcp;

use React\Stream\DuplexStreamInterface;
use Reactphp\Framework\Bridge\Interface\MessageComponentInterface;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;

class TcpBridge implements TcpBridgeInterface
{

    protected $component;

    public function __construct(MessageComponentInterface $component)
    {
        $this->component = $component;
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        echo "TcpBridge onOpen\n";
        $this->component->onOpen($stream, $info);
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        echo "TcpBridge onClose\n";
        $this->component->onClose($stream, $reason);
    }

    public function onError(DuplexStreamInterface $conn, \Exception $e)
    {
        echo "TcpBridge onError\n";
        $this->component->onError($conn, $e);
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        echo $msg;
        $this->component->onMessage($stream, $msg);
    }
}