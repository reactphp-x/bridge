<?php

namespace ReactphpX\Bridge\Business\ClientStream;

use React\Socket\ConnectorInterface;

interface ConnectorStreamInterface extends ConnectorInterface
{
    public function disconnect();

    public function getStatus();
}