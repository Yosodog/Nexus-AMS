<?php

namespace App\GraphQL\Models;

use Iterator;

class BankRecords implements Iterator
{
    private array $bankRecs;  // Array to store BankRec objects
    private int $position;    // Current position of the iterator

    public function __construct(array $bankRecs = [])
    {
        $this->bankRecs = $bankRecs;
        $this->position = 0;
    }

    /**
     * Return the current element.
     *
     * @return BankRecord
     */
    public function current(): BankRecord
    {
        return $this->bankRecs[$this->position];
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
        return isset($this->bankRecs[$this->position]);
    }

    /**
     * Rewind the Iterator to the first element.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Add a BankRecord object to the collection.
     *
     * @param BankRecord $bankRec
     */
    public function add(BankRecord $bankRec): void
    {
        $this->bankRecs[] = $bankRec;
    }

    /**
     * Get the total count of BankRecords.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->bankRecs);
    }
}
