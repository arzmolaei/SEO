<?php

namespace App\Events\Pages;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;

final readonly class PagePromoted
{
    public function __construct(public User $actor, public Project $project, public Page $page) {}
}
