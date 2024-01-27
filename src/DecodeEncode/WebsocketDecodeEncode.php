<?php

namespace Reactphp\Framework\Bridge\DecodeEncode;

use Ratchet\RFC6455\Messaging\Frame;
use MessagePack\MessagePack;
use MessagePack\BufferUnpacker;
use Reactphp\Framework\Bridge\Interface\DecodeEncodeInterface;

class WebsocketDecodeEncode implements DecodeEncodeInterface
{
    protected $bufferUpacker;

    public function __construct()
    {
        $this->bufferUpacker = new BufferUnpacker();
    }

    public function encode($data)
    {
        return new Frame(MessagePack::pack($data), true, Frame::OP_BINARY);
    }

    public function decode($buffer)
    {
        $this->bufferUpacker->append($buffer);
        if ($messages = $this->bufferUpacker->tryUnpack()) {
            $this->bufferUpacker->release();
            return $messages;
        }
        return null;
    }
}