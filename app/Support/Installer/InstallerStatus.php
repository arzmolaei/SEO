<?php

namespace App\Support\Installer;

use App\Support\InstallationState;

class InstallerStatus
{
    public function __construct(
        private readonly InstallationState $installationState,
        private readonly InstallerJournal $journal,
    ) {}

    /** @return array{phase: InstallerPhase, installed: bool, journal: array<string, mixed>} */
    public function snapshot(): array
    {
        $journal = $this->journal->read();
        $installed = $this->installationState->isInstalled();

        if ($installed) {
            $phase = InstallerPhase::Installed;
        } else {
            $phaseValue = $journal['phase'] ?? null;
            $phase = is_string($phaseValue)
                ? (InstallerPhase::tryFrom($phaseValue) ?? InstallerPhase::Preflight)
                : InstallerPhase::Preflight;
        }

        return [
            'phase' => $phase,
            'installed' => $installed,
            'journal' => $journal,
        ];
    }
}
