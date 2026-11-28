<?php

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Symfony\Component\Uid\UuidV7;

/**
 * Backfill enforced plan-feature gates that `SubscriptionPlanSeeder` never wrote.
 *
 * The admin plan editor (`admin/plans/page.tsx`) treats a small set of features
 * as "fixed on for everyone" and hides them; that is also the set the seeder was
 * supposed to seed. Three of them were missing from every plan row, and because
 * `PlanEngine::hasFeature()` returns **false** for an absent key, the gate that
 * guards the feature was closed platform-wide — including on the top plan:
 *
 *   | Gate                  | Enforced by                                        | Was            |
 *   |-----------------------|----------------------------------------------------|----------------|
 *   | assignments_enabled   | QuotaService `assignments` (registry)              | blocked 100%   |
 *   | blog_enabled          | `feature:blog_enabled` route + TeacherBlogController | blocked 100% |
 *   | custom_domain         | TeacherDomainController (connect / show)           | blocked 100%   |
 *
 * Assignments are the clearest defect: every plan ships an `assignments_limit`
 * (10 / 100 / unlimited) and the feature catalog advertises the feature, yet
 * `POST /api/v1/assignments` answered 403 for everyone.
 *
 * This migration is **add-only**: it never overwrites an existing value, so the
 * deliberate per-tier differentiators in the seeder (e.g. `custom_branding_enabled`
 * and `multi_teacher_enabled` = false on Starter) are preserved. Prices and
 * numeric limits are not touched here.
 */
return new class extends Migration
{
    /**
     * Gate => value to write when the key is absent on a plan.
     *
     * `custom_domain` is safe to open: a connected domain is only ever served
     * and only ever receives a certificate after DNS ownership verification
     * (`TeacherDomainController@verify` + the edge `public/tls/ask` check).
     *
     * @var array<string, string>
     */
    private const ENFORCED_GATES = [
        'assignments_enabled' => 'true',
        'blog_enabled' => 'true',
        'custom_domain' => 'true',
    ];

    public function up(): void
    {
        $touched = false;

        foreach (SubscriptionPlan::withTrashed()->get() as $plan) {
            $existing = PlanFeature::where('plan_id', $plan->id)
                ->pluck('feature_key')
                ->all();

            foreach (self::ENFORCED_GATES as $key => $value) {
                if (in_array($key, $existing, true)) {
                    continue; // add-only: never clobber a configured value
                }

                PlanFeature::create([
                    'id' => UuidV7::generate(),
                    'plan_id' => $plan->id,
                    'feature_key' => $key,
                    'feature_value' => $value,
                    'feature_type' => 'boolean',
                    'sort_order' => 100,
                ]);

                $touched = true;
            }
        }

        if ($touched) {
            // Every tenant's feature snapshot lives in cache — bump the version
            // so the opened gates take effect immediately.
            try {
                \App\Plans\PlanEngine::bumpVersion();
            } catch (\Throwable) {
                // A momentarily unavailable cache must not fail the migration —
                // the bump is retried on the next change.
            }
        }
    }

    public function down(): void
    {
        // We cannot know which rows this migration created vs. pre-existing
        // ones, and removing a feature gate is not a safe rollback. No-op.
    }
};
