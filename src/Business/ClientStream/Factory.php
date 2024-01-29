<?php

namespace Reactphp\Framework\Bridge\Business\ClientStream;

use React\Socket\ConnectorInterface;

class Factory
{
    public static function createConnector($uri, ConnectorInterface $connector = null)
    {
        $protocol = parse_url($uri, PHP_URL_SCHEME);
        if ($protocol == 'tcp') {
            return new TcpConnectorStream($connector);
        }

        return new TcpConnectorStream($connector);
    }
}
