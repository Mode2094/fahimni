<?php

namespace App\Plans;

use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;

/**
 * Quota / Limits Engine — the single enforcement point.
 * Every "can I create this?" decision in the codebase goes through here:
 *
 *   QuotaService::assertCan($tenantId, 'courses');
 *   QuotaService::assertCan($tenantId, 'storage', $fileBytes);
 *
 * Messages are friendly, dynamic and never plan-name specific.
 */
class QuotaService
{
    public function __construct(
        private readonly PlanEngine $engine,
        private readonly UsageService $usage,
    ) {}

    /**
     * Assert a feature gate + limit for a resource.
     *
     * @throws FeatureUnavailableException
     * @throws QuotaExceededException
     */
    public function assertCan(string $tenantId, string $resource, int|float $increment = 1): void
    {
        $meta = $this->usage->registry()[$resource] ?? null;

        if (! $meta) {
            return; // unknown resource = not enforced
        }

        // Feature gate first.
        if ($meta['gate'] && ! $this->engine->hasFeature($tenantId, $meta['gate'])) {
            throw new FeatureUnavailableException($meta['label_ar']);
        }

        // Storage is a bytes limit.
        if ($resource === 'storage') {
            $this->assertStorage($tenantId, (int) $increment);

            return;
        }

        $limit = $this->engine->limit($tenantId, (string) $meta['limit']);

        if ($limit === null) {
            return; // unlimited or not configured
        }

        $used = (int) $this->usage->usageOf($tenantId, $resource);

        if ($used + (int) $increment > $limit) {
            throw new QuotaExceededException(
                label: $meta['label_ar'],
                used: $used,
                limit: $limit,
            );
        }
    }

    public function assertStorage(string $tenantId, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $limitMb = $this->engine->value($tenantId, 'storage_limit_mb');

        if ($limitMb === 'unlimited' || (int) $limitMb <= 0) {
            return;
        }

        $limitBytes = (int) $limitMb * 1024 * 1024;
        $used = $this->usage->storageBytes($tenantId);

        if ($used + $bytes > $limitBytes) {
            throw new QuotaExceededException(
                label: 'مساحة التخزين',
                used: (int) floor($used / 1024 / 1024),
                limit: (int) $limitMb,
                usedUnit: 'MB',
                limitUnit: 'MB',
            );
        }
    }

    /**
     * Full status for the teacher dashboard: used / limit / remaining / pct.
     */
    public function status(string $tenantId): array
    {
        $usage = $this->usage->all($tenantId);
        $status = [];

        foreach ($this->usage->registry() as $resource => $meta) {
            $limit = $meta['limit'] ? $this->engine->value($tenantId, $meta['limit']) : null;
            $used = $usage[$resource] ?? 0;

            if ($resource === 'storage') {
                $limitBytes = $limit === 'unlimited' || ! is_numeric($limit)
                    ? null
                    : (int) $limit * 1024 * 1024;
                $status[$resource] = $this->entry($used, $limitBytes, 'bytes');

                continue;
            }

            $status[$resource] = $this->entry($used, $limit, 'count');
        }

        return $status;
    }

    private function entry(int|float $used, mixed $limit, string $unit): array
    {
        $unlimited = $limit === null || $limit === 'unlimited' || $limit === 0;

        if ($unlimited) {
            return [
                'used' => $used,
                'limit' => null,
                'remaining' => null,
                'pct' => $used > 0 ? 100 : 0,
                'unlimited' => true,
                'unit' => $unit,
            ];
        }

        $limitN = (int) $limit;
        $remaining = max(0, $limitN - (int) $used);
        $pct = $limitN > 0 ? min(100, round(((int) $used / $limitN) * 100, 1)) : 0;

        return [
            'used' => $used,
            'limit' => $limitN,
            'remaining' => $remaining,
            'pct' => $pct,
            'unlimited' => false,
            'unit' => $unit,
        ];
    }
}
