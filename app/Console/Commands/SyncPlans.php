<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Console\Command;

/**
 * plans:sync — أعد الخطط الكنسية (Starter / Professional / Enterprise)
 * من SubscriptionPlanSeeder في أي بيئة، وليس فقط في Docker.
 *
 * بدون --force: يُنشئ الخطط المفقودة فقط ولا يلمس الخطط الموجودة
 * (حتى لا يُفقد تعديل الأدمن الحالي).
 * مع --force: يصالح الميزات والأسعار لتطابق رقمياً قيم الـ Seeder.
 */
class SyncPlans extends Command
{
    protected $signature = 'plans:sync {--force : إعادة الخطط الموجودة لتطابق القيم الكنسية}';

    protected $description = 'Create missing canonical plans (or reconcile all with --force) from the seeder source of truth.';

    public function handle(): int
    {
        $existing = SubscriptionPlan::count();

        if ($existing > 0 && ! $this->option('force')) {
            $this->line("وجد {$existing} خطة موجودة — الخطط غير المفقودة تُركت كما هي (استخدم --force للمصالحة).");

            return self::SUCCESS;
        }

        $this->call(SubscriptionPlanSeeder::class);

        $this->info($this->option('force')
            ? 'تمت مصالحة الخطط لتطابق القيم الكنسية في مهيّئ الخطط.'
            : 'تم إنشاء الخطط الكنسية المفقودة.');

        return self::SUCCESS;
    }
}