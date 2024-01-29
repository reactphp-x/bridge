<?php

namespace Reactphp\Framework\Bridge\Business\ServerStream;

use React\EventLoop\LoopInterface;

class Factory
{
    public static function createServerStream($uri, array $context = array(), LoopInterface $loop = null)
    {
        $protocol = parse_url($uri, PHP_URL_SCHEME);
        if ($protocol == 'tcp') {
            return new TcpServerStream($uri, $context, $loop);
        }

        return new TcpServerStream($uri, $context, $loop);
    }
}