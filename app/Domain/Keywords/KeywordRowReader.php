<?php

namespace App\Domain\Keywords;

interface KeywordRowReader
{
    /** @return iterable<int, array<string, mixed>> */
    public function rows(string $path): iterable;
}
