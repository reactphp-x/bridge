<?php

namespace ReactphpX\Bridge\Interface;

use Evenement\EventEmitterInterface;

interface ServerInterface extends CreateConnectionInterface, MessageComponentInterface, EventEmitterInterface, CallInterface
{
}
