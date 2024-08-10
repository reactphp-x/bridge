<?php

namespace ReactphpX\Bridge\Verify;

use ReactphpX\Bridge\Interface\VerifyInterface;

class VerifyUuid implements VerifyInterface
{

    protected $uuidToSomething;

    public function __construct($uuidToSomething)
    {
        if (isset($uuidToSomething[0])) {
            $tmp = [];
            foreach ($uuidToSomething as $value) {
                $tmp[$value] = '';
            }
            $uuidToSomething = $tmp;
        }
        $this->uuidToSomething = $uuidToSomething;
    }

    public function verify($uuid)
    {
        return isset($this->uuidToSomething[$uuid]);
    }

    public function getUuidBySomething($something)
    {
        if (!$something) {
            return false;
        }
        return array_search($something, $this->uuidToSomething);
    }

    public function getSomethingByUuid($uuid)
    {
        if (!isset($this->uuidToSomething[$uuid])) {
            return false;
        }
        return $this->uuidToSomething[$uuid];
    }
}
