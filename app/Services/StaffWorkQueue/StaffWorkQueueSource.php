<?php

namespace App\Services\StaffWorkQueue;

interface StaffWorkQueueSource
{
    public function type(): string;

    public function label(): string;

    public function ability(): string;

    /**
     * @return list<StaffWorkItem>
     */
    public function load(): array;
}
