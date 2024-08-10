<?php

namespace ReactphpX\Bridge\Interface;

use React\Socket\ConnectorInterface;
use Evenement\EventEmitterInterface;

interface ClientInterface extends MessageComponentInterface, EventEmitterInterface, CallInterface
{
    public function setConnector(ConnectorInterface $connector);
    public function setDecodeEncode(DecodeEncodeInterface $decodeEncode);
    public function getUuid();
}
