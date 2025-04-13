<?php

namespace App\GraphQL\Models;

use Iterator;

class Wars implements Iterator
{
    private array $wars;
    private int $position = 0;

    public function __construct(array $wars = [])
    {
        $this->wars = $wars;
    }

    public function current(): War
    {
        return $this->wars[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->wars[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function add(War $war): void
    {
        $this->wars[] = $war;
    }
}