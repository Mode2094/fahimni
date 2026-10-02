<?php

namespace App\Observers;

use App\Models\SubscriptionPlan;
use App\Plans\PlanEngine;

class SubscriptionPlanObserver
{
    public function saved(SubscriptionPlan $plan): void
    {
        PlanEngine::bumpVersion();
    }

    public function deleted(SubscriptionPlan $plan): void
    {
        PlanEngine::bumpVersion();
    }
}
