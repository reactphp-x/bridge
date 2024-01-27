<?php

namespace Reactphp\Framework\Bridge\Interface;

use Evenement\EventEmitterInterface;

interface ServerInterface extends CreateConnectionInterface, MessageComponentInterface, EventEmitterInterface
{
}
