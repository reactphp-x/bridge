<?php

namespace ReactphpX\Bridge\Business\ClientStream;

use React\Socket\ConnectorInterface;

class Factory
{
    protected $connectorStreamClass = [];

    public static function createConnector($uri, ConnectorInterface $connector = null)
    {
        $protocol = parse_url($uri, PHP_URL_SCHEME);

        if (isset(static::$connectorStreamClass[$protocol])) {
            $class = static::$connectorStreamClass[$protocol];
            return new $class($connector);
        }

        if ($protocol == 'tcp') {
            return new TcpConnectorStream($connector);
        } else if ($protocol == 'unix') {
            return new UnixConnectorStream($connector);
        }

        return new TcpConnectorStream($connector);
    }

    public static function registerConnector($protocol, $class)
    {
        if (!class_exists($class)) {
            throw new \Exception("Class $class not found");
        }

        if (!is_subclass_of($class, ConnectorStreamInterface::class)) {
            throw new \Exception("Class $class not implement ConnectorStreamInterface");
        }
        static::$connectorStreamClass[$protocol] = $class;
    }

    public static function unregisterConnector($protocol)
    {
        unset(static::$connectorStreamClass[$protocol]);
    }
}
