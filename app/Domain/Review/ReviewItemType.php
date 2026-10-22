<?php

namespace App\Domain\Review;

enum ReviewItemType: string
{
    case Intent = 'intent';
    case Cluster = 'cluster';
}
