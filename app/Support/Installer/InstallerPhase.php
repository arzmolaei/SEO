<?php

namespace App\Support\Installer;

enum InstallerPhase: string
{
    case Preflight = 'preflight';
    case Environment = 'environment';
    case Database = 'database';
    case Migrations = 'migrations';
    case Owner = 'owner';
    case Operations = 'operations';
    case Finalize = 'finalize';
    case Installed = 'installed';
}
