<?php

namespace App\Domain\Pages;

enum PageKind: string
{
    case Existing = 'existing';
    case Planned = 'planned';
}
