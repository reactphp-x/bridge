<?php

namespace ReactphpX\Bridge;

class Info implements \ArrayAccess
{
    public $info = [];

    public function __construct($array = [])
    {
        $this->info = $array;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->info[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return isset($this->info[$offset]) ? $this->info[$offset] : null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->info[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->info[$offset]);
    }

    public function __set($name, $value)
    {
        $this->info[$name] = $value;
    }

    public function __get($name)
    {
        return isset($this->info[$name]) ? $this->info[$name] : null;
    }

    public function toArray()
    {
        return $this->info;
    }
}
