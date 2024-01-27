<?php

namespace Reactphp\Framework\Bridge\Interface;

interface VerifyInterface
{
    public function verify($uuid);

    public function getUuidBySomething($something);

    public function getSomethingByUuid($uuid);
}