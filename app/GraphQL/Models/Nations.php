<?php

namespace App\GraphQL\Models;

class Nations implements \Iterator
{
    private array $nations = [];  // Array to store Nation objects
    private int $position = 0;    // Current position of the iterator

    public function __construct(array $nations)
    {
        $this->nations = $nations;
        $this->position = 0;
    }

    /**
     * Return the current element.
     *
     * @return Nation
     */
    public function current(): Nation
    {
        return $this->nations[$this->position];
    }

    /**
     * Move forward to next element.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Return the key of the current element.
     *
     * @return int
     */
    public function key(): mixed
    {
        return $this->position;
    }

    /**
     * Checks if current position is valid.
     *
     * @return bool
     */
    public function valid(): bool
    {
        return isset($this->nations[$this->position]);
    }

    /**
     * Rewind the Iterator to the first element.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Add a Nation object to the collection.
     *
     * @param Nation $nation
     */
    public function add(Nation $nation): void
    {
        $this->nations[] = $nation;
    }

    /**
     * Get the total count of nations.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->nations);
    }
}
