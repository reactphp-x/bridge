<?php

namespace Reactphp\Framework\Bridge\Business\ServerStream;

use React\EventLoop\LoopInterface;

class Factory
{
    protected $serverStreamClass = [];
    public static function createServerStream($uri, array $context = array(), LoopInterface $loop = null)
    {
        $protocol = parse_url($uri, PHP_URL_SCHEME);

        if (isset(static::$serverStreamClass[$protocol])) {
            $class = static::$serverStreamClass[$protocol];
            return new $class($uri, $context, $loop);
        }

        if ($protocol == 'tcp') {
            return new TcpServerStream($uri, $context, $loop);
        }

        return new TcpServerStream($uri, $context, $loop);
    }

    public static function registerServerStream($protocol, $class)
    {
        if (!class_exists($class)) {
            throw new \Exception("Class $class not found");
        }

        if (!is_subclass_of($class, ServerStreamInterface::class)) {
            throw new \Exception("Class $class not implement ServerStreamInterface");
        }
        static::$serverStreamClass[$protocol] = $class;
    }   

    public static function unregisterServerStream($protocol)
    {
        unset(static::$serverStreamClass[$protocol]);
    }
}