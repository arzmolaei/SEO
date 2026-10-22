<?php

namespace App\Domain\Keywords;

enum MappingConflictType: string
{
    case CompetingPages = 'competing_pages';
    case OverloadedPage = 'overloaded_page';
    case HighDemandUnmapped = 'high_demand_unmapped';
    case InvalidTarget = 'invalid_target';
}
