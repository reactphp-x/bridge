<?php

namespace Reactphp\Framework\Bridge\DecodeEncode;

use MessagePack\MessagePack;
use MessagePack\BufferUnpacker;
use Reactphp\Framework\Bridge\Interface\DecodeEncodeInterface;

class TcpDecodeEncode implements DecodeEncodeInterface
{
    protected $bufferUpacker;

    public function __construct()
    {
        $this->bufferUpacker = new BufferUnpacker();
    }

    public function encode($data)
    {
        return MessagePack::pack($data);
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