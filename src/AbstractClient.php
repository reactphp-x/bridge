<?php

namespace ReactphpX\Bridge;

use React\Socket\ConnectorInterface;
use ReactphpX\Bridge\Interface\ClientInterface;
use ReactphpX\Bridge\Interface\DecodeEncodeInterface;

abstract class AbstractClient implements ClientInterface
{
    protected $uuid;

    protected $connector;

    /**
     * @var DecodeEncodeInterface
     */
    protected $decodeEncode;
    protected $decodeEncodeClass;

    public function __construct($uuid)
    {
        $this->uuid = $uuid;
    }

    public function setConnector(ConnectorInterface $connector)
    {
        $this->connector = $connector;
    }

    public function setDecodeEncode(DecodeEncodeInterface $decodeEncode)
    {
        $this->decodeEncode = $decodeEncode;
        $this->decodeEncodeClass = get_class($decodeEncode);
    }

    public function getUuid()
    {
        return $this->uuid;
    }
    
    abstract public function start();

    abstract public function stop();

}