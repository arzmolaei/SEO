<?php

namespace App\Domain\Keywords;

enum ClusterReviewState: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
}
