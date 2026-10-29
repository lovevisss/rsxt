<?php

namespace App\Services;

use App\Models\User;

class ProfileCorrectionAccess
{
    public function __construct(private readonly LegacyAccess $legacyAccess) {}

    public function canApply(User $user): bool
    {
        return $this->legacyAccess->canOpenModule($user, 'corrections');
    }

    public function isReviewer(User $user): bool
    {
        return $this->legacyAccess->canOpenModule($user, 'correction_review');
    }
}
