<?php

namespace App\Domain\Keywords;

enum KeywordIntent: string
{
    case Informational = 'informational';
    case Commercial = 'commercial';
    case Transactional = 'transactional';
    case Navigational = 'navigational';
    case Local = 'local';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
