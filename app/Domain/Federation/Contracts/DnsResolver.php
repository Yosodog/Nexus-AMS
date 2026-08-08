<?php

namespace App\Domain\Federation\Contracts;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
