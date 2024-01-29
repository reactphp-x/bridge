<?php

namespace Reactphp\Framework\Bridge;

use React\Socket\ConnectorInterface;
use Reactphp\Framework\Bridge\Interface\ClientInterface;

abstract class AbstractClient implements ClientInterface
{
    protected $uuid;

    protected $connector;

    public function __construct($uuid)
    {
        $this->uuid = $uuid;
    }

    public function setConnector(ConnectorInterface $connector)
    {
        $this->connector = $connector;
    }

    public function getUuid()
    {
        return $this->uuid;
    }
    
    abstract public function start();

    abstract public function stop();

}