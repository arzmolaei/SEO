<?php

namespace App\Domain\Keywords;

enum IntentConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
