<?php

namespace App\GraphQL\Models;

class Alliances implements \Iterator
{

    private array $alliances;  // Array to store Alliance objects
    private int $position;    // Current position of the iterator

    /**
     * @param array $alliances
     */
    public function __construct(array $alliances)
    {
        $this->alliances = $alliances;
        $this->position = 0;
    }

    /**
     * @return Alliance
     */
    public function current(): Alliance
    {
        return $this->alliances[$this->position];
    }

    /**
     * @return void
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return isset($this->alliances[$this->position]);
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Add an Alliance object to the collection.
     *
     * @param Alliance $alliance
     */
    public function add(Alliance $alliance): void
    {
        $this->$alliance[] = $alliance;
    }
}
