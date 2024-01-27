<?php

namespace Reactphp\Framework\Bridge;

use React\Socket\ConnectorInterface;
use Reactphp\Framework\Bridge\Interface\ClientInterface;

abstract class AbstractClient implements ClientInterface
{
    protected $connector;

    public function setConnector(ConnectorInterface $connector)
    {
        $this->connector = $connector;
    }
    
    abstract public function start();

    abstract public function stop();

}