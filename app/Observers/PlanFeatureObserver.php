<?php

namespace App\Observers;

use App\Models\PlanFeature;
use App\Plans\PlanEngine;

/**
 * Any plan-feature change bumps the global plan version so every tenant's
 * cached snapshot is recomputed on the next request — upgrades, downgrades
 * and admin edits apply instantly.
 */
class PlanFeatureObserver
{
    public function saved(PlanFeature $feature): void
    {
        PlanEngine::bumpVersion();
    }

    public function deleted(PlanFeature $feature): void
    {
        PlanEngine::bumpVersion();
    }
}
