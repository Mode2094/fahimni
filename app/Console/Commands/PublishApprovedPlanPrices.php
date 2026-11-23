<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Services\FreemiusPriceSyncService;
use Illuminate\Console\Command;

/**
 * plans:publish-approved-prices — انشر أسعار الخطط المعتمدة إلى Freemius.
 *
 * الأسعار المعتمدة (USD): الانطلاقة 29/299 · المحترف 59/599 · المتقدم 89/899.
 *
 * لا يُعتمد أي سعر محلياً إلا عبر FreemiusPriceSyncService، التي ترسل المبلغ
 * إلى Freemius، تعيد قراءة السعر الفعلي، ثم تعتمده محلياً فقط عند تطابقه.
 * عند الفشل يبقى السعر المعروض كما هو ويُسجَّل الطلب كـ«معلّق» — لا نجاح وهمي.
 */
class PublishApprovedPlanPrices extends Command
{
    protected $signature = 'plans:publish-approved-prices {--dry-run : اعرض ما سيُنشر دون الاتصال بمزود الدفع}';

    protected $description = 'Publish the approved plan prices to Freemius and adopt them locally only after confirmation.';

    /** @var array<string, array{monthly: float, yearly: float}> */
    private const PRICES = [
        'starter' => ['monthly' => 29.0, 'yearly' => 299.0],
        'professional' => ['monthly' => 59.0, 'yearly' => 599.0],
        'enterprise' => ['monthly' => 89.0, 'yearly' => 899.0],
    ];

    public function handle(): int
    {
        $service = app(FreemiusPriceSyncService::class);
        $request = request();
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];
        $failed = false;

        foreach (self::PRICES as $slug => $prices) {
            // العمود الثالث له سلغان تاريخيان: enterprise (الإنتاج) و elite.
            $slugs = $slug === 'enterprise' ? ['enterprise', 'elite'] : [$slug];

            $plan = SubscriptionPlan::withTrashed()->whereIn('slug', $slugs)->first();

            if (! $plan) {
                $rows[] = [$slug, '-', '-', 'لم تُعثر على الخطة', 'SKIPPED'];
                continue;
            }

            $toPublish = [];

            foreach ($prices as $cycle => $target) {
                $current = $cycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

                if ($current !== null && abs((float) $current - $target) < 0.005) {
                    $rows[] = [$plan->slug, $cycle, $this->money($target), 'مطابق مسبقاً', 'synced'];
                    continue;
                }

                $toPublish[$cycle] = $target;
            }

            if ($toPublish === []) {
                continue;
            }

            if ($dryRun) {
                foreach ($toPublish as $cycle => $target) {
                    $rows[] = [$plan->slug, $cycle, $this->money($target), 'سيُنشر', 'dry-run'];
                }

                continue;
            }

            $result = $service->publish($plan, $toPublish, $request);

            if (($result['locked'] ?? false) === true) {
                $rows[] = [$plan->slug, '-', '-', 'مشغول بحقول أخرى — أعد المحاولة', 'FAILED'];
                $failed = true;

                continue;
            }

            foreach ($result['results'] as $cycle => $entry) {
                $ok = (bool) ($entry['ok'] ?? false);
                $status = (string) ($entry['status'] ?? 'unknown');

                $rows[] = [
                    $plan->slug,
                    is_string($cycle) ? $cycle : '-',
                    $this->money((float) ($entry['new_price'] ?? 0)),
                    (string) ($entry['message'] ?? $status),
                    $ok ? 'OK' : 'FAILED',
                ];

                if (! $ok) {
                    $failed = true;
                }
            }
        }

        $this->table(['الخطة', 'الدورة', 'السعر (USD)', 'الحالة', 'النتيجة'], $rows);

        if ($failed) {
            $this->error('تعذّر نشر بعض الأسعار — لم تُعتمد محلياً وتبقى «معلّقة». لم يُعرض أي سعر غير مؤكد.');

            return self::FAILURE;
        }

        $this->info('تمت مزامنة الأسعار المعتمدة والتحقق منها لدى Freemius.');

        return self::SUCCESS;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
