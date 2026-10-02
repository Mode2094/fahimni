<?php

namespace Database\Seeders;

use App\Models\ProviderCostRule;
use Illuminate\Database\Seeder;

/**
 * قواعد التكلفة الافتراضية للمزودين — أسعار تقريبية قابلة للتعديل من
 * قاعدة البيانات (لوحة الأدمن → BI). ليست أسعاراً نهائية؛ المصدر الوحيد
 * للحقيقة هو جدول provider_cost_rules.
 */
class CostRulesSeeder extends Seeder
{
    public function run(): void
    {
        // الأسعار الرسمية من bunny.net/pricing/stream (2026):
        //   Encoding: مجاني · Storage: $0.01/GB/شهر · Delivery: $0.005–0.06/GB
        //   (نعتمد $0.01 = Standard أوروبا/أمريكا) · حد أدنى $1/شهر
        //   Live: لا تسعيرة ساعة منفصلة — يُفوتر كتخزين+بث؛ نقدّر
        //   $0.03/ساعة تحفظياً (يشمل تسجيل + مشاهدة)
        $rules = [
            // ─── Bunny Stream (الأساسي) ────────────────
            ['bunny', 'storage', 'GB', 0.010000, 'USD', ['delivery_factor' => 2, 'expected_emails_month' => 100, 'min_monthly' => 1]],
            ['bunny', 'delivery', 'GB', 0.010000, 'USD', ['delivery_factor' => 2]],
            ['bunny', 'encoding', 'video', 0.000000, 'USD', []],
            ['bunny', 'live', 'hour', 0.030000, 'USD', []],

            // ─── Cloudflare Stream (الاحتياطي) ─────────
            ['cloudflare', 'storage', 'GB', 0.050000, 'USD', ['delivery_factor' => 2, 'expected_emails_month' => 100, 'min_monthly' => 5]],
            ['cloudflare', 'delivery', 'GB', 0.001000, 'USD', []],
            ['cloudflare', 'encoding', 'video', 0.000000, 'USD', []],
            ['cloudflare', 'live', 'hour', 0.080000, 'USD', []],

            // ─── خدمات عامة ─────────────────────────────
            ['email', 'email', 'email', 0.000100, 'USD', []],
            ['sms', 'sms', 'sms', 0.030000, 'USD', []],
        ];

        foreach ($rules as [$provider, $resource, $unit, $cost, $currency, $metadata]) {
            ProviderCostRule::updateOrCreate(
                ['provider' => $provider, 'resource' => $resource],
                [
                    'unit' => $unit,
                    'cost' => $cost,
                    'currency' => $currency,
                    'metadata' => $metadata,
                    'is_active' => true,
                ],
            );
        }
    }
}
