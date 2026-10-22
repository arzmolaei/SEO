<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case SeoManager = 'seo_manager';
    case SeoSpecialist = 'seo_specialist';
    case ContentWriter = 'content_writer';
    case Viewer = 'viewer';

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageProjects(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::SeoManager], true);
    }
}
