<?php

namespace App\GraphQL\Models;

use Iterator;

class Alliances implements Iterator
{
    private array $alliances;  // Array to store Alliance objects

    private int $position;    // Current position of the iterator

    public function __construct(array $alliances)
    {
        $this->alliances = $alliances;
        $this->position = 0;
    }

    public function current(): Alliance
    {
        return $this->alliances[$this->position];
    }

    public function next(): void
    {
        $this->position++;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->alliances[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Add an Alliance object to the collection.
     */
    public function add(Alliance $alliance): void
    {
        $this->alliances[] = $alliance;
    }
}
