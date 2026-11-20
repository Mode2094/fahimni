<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * TenantSlugService — رابط المعلم (slug).
 *
 * الرابط هو اسم المعلم الصافي (مثل `mohmmad`) لا كود عشوائي، ويُستخدم في
 * المسار `/t/{slug}` وفي النطاق الفرعي `{slug}.{platform_domain}`.
 *
 * عند تشابه الأسماء تُضاف لاحقة رقمية متسلسلة (mohmmad ثم mohmmad-2 ثم
 * mohmmad-3 ...) بدل الأكواد العشوائية.
 */
class TenantSlugService
{
    /**
     * كلمات محجوزة: أسماء بنية تحتية/مسارات قياسية لا يجوز حجزها كرابط
     * لمعلّم (وإلا يفسد التوجيه أو يوهم المستخدم).
     */
    private const RESERVED = [
        'www', 'app', 'api', 'admin', 'administrator', 'root', 'system', 'support',
        'teacher', 'teachers', 'student', 'students', 'tutor', 'school', 'academy',
        'mail', 'email', 'webmail', 'smtp', 'imap', 'pop', 'ftp', 'sftp', 'cdn',
        'static', 'assets', 'media', 'video', 'videos', 'files', 'storage', 'uploads',
        'img', 'images', 'css', 'js', 'fonts',
        'login', 'logout', 'register', 'signup', 'signin', 'auth', 'sso', 'oauth',
        'account', 'accounts', 'billing', 'invoice', 'invoices', 'pay', 'payment',
        'payments', 'checkout', 'subscribe', 'subscription', 'pricing', 'plans',
        'dashboard', 'panel', 'cpanel', 'manage', 'console',
        'blog', 'news', 'help', 'docs', 'doc', 'status', 'health', 'monitoring',
        'about', 'contact', 'terms', 'privacy', 'legal', 'dmca', 'policy', 'security',
        'dev', 'develop', 'development', 'test', 'testing', 'stage', 'staging', 'demo',
        'preview', 'sandbox', 'beta', 'alpha', 'localhost',
        'store', 'shop', 'cart', 'order', 'orders', 'courses', 'course', 'lessons',
        'lesson', 'exams', 'exam', 'certificate', 'certificates', 'live', 'stream',
        'ns', 'ns1', 'ns2', 'mx', 'mx1', 'dns', 'vpn', 'proxy', 'git', 'repo',
        'search', 'go', 'link', 'links', 'redirect', 'r', 't', 's', 'p', 'e',
        'looksloop', 'looks-loop', 'fahimni', 'fahmini',
    ];

    /** الأساس: اسم المعلم بصيغة ASCII صالحة للرابط. */
    public function base(string $name): string
    {
        $base = trim(Str::slug($name, '-', 'en'), '-');

        if ($base === '') {
            $base = 'mysite';
        }

        return mb_substr($base, 0, 48);
    }

    public function normalize(string $slug): string
    {
        return mb_strtolower(trim($slug));
    }

    /** صيغة صحيحة: حروف/أرقام إنجليزية وشرطات، بطول 2..63، بلا شرطة طرفية. */
    public function isValidFormat(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/', $this->normalize($slug));
    }

    public function isReserved(string $slug): bool
    {
        $slug = $this->normalize($slug);

        return in_array($slug, self::RESERVED, true)
            || in_array($slug, $this->platformLabels(), true);
    }

    /**
     * هل الرابط متاح؟ (تجاهل مستأجراً واحداً عند إعادة الحفظ لنفسه).
     */
    public function available(string $slug, ?string $exceptTenantId = null): bool
    {
        return $this->unavailabilityReason($slug, $exceptTenantId) === null;
    }

    /** سبب عدم الإتاحة (للرسائل)، أو null إن كان متاحاً. */
    public function unavailabilityReason(string $slug, ?string $exceptTenantId = null): ?string
    {
        $slug = $this->normalize($slug);

        if (! $this->isValidFormat($slug)) {
            return 'invalid';
        }

        if ($this->isReserved($slug)) {
            return 'reserved';
        }

        // مقارنة غير حساسة لحالة الأحرف (روابط قديمة بحروف كبيرة)؛
        // withTrashed لأن الفهرس الفريد على slug يشمل المحذوف مؤقتاً.
        $taken = Tenant::withTrashed()
            ->whereRaw('lower(slug) = ?', [$slug])
            ->when($exceptTenantId, fn ($q) => $q->where('id', '!=', $exceptTenantId))
            ->exists();

        return $taken ? 'taken' : null;
    }

    /**
     * توليد رابط فريد مبني على الاسم: `name` ثم `name-2` ثم `name-3` ...
     */
    public function generate(string $name): string
    {
        $base = $this->base($name);

        if ($this->available($base)) {
            return $base;
        }

        for ($i = 2; $i <= 9999; $i++) {
            $suffix = '-'.$i;
            $stem = mb_substr($base, 0, 63 - strlen($suffix));
            $candidate = $stem.$suffix;

            if ($this->available($candidate)) {
                return $candidate;
            }
        }

        // احتياطي نظري فقط (لا يحدث عملياً).
        return mb_substr($base, 0, 56).'-'.Str::lower(Str::random(6));
    }

    /**
     * إنشاء مستأجر جديد برابط مشتق من الاسم، مع إعادة المحاولة عند تعارض
     * نادر في الفهرس الفريد (تسجيلان متزامنان بنفس الاسم).
     *
     * @param  array<string, mixed>  $attributes  بلا حقل slug
     */
    public function createTenant(array $attributes, string $name): Tenant
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Tenant::create([
                    ...$attributes,
                    'slug' => $this->generate($name),
                ]);
            } catch (QueryException $e) {
                if ($attempt === 4 || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        // غير قابل للوصول (الحلقة تُرجع أو ترمي).
        throw new \RuntimeException('Unable to allocate a unique tenant slug.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }

    /** أسماء النطاق الأساسي للمنصة (محجوزة دائماً). */
    private function platformLabels(): array
    {
        $domain = (string) PlatformSetting::get('platform_domain', '');
        $labels = [];

        foreach (explode(',', $domain) as $part) {
            $first = explode('.', trim(mb_strtolower($part)))[0];

            if ($first !== '') {
                $labels[] = $first;
            }
        }

        return $labels;
    }
}
