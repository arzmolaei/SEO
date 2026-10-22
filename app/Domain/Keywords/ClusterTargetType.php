<?php

namespace App\Domain\Keywords;

enum ClusterTargetType: string
{
    case ExistingPage = 'existing_page';
    case PlannedPage = 'planned_page';
    case MergeTarget = 'merge_target';
    case RedirectTarget = 'redirect_target';
    case Ignore = 'ignore';
}
