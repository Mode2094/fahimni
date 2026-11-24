<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\FeatureService;
use App\Services\TenantSlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherDomainController extends Controller
{
    // Human-readable DNS answer prefixes. Kept as constants so parsing is
    // byte-exact: the arrow (→) is multi-byte UTF-8, so an off-by-one offset
    // would silently read part of the arrow instead of the target.
    private const REC_CNAME = 'CNAME → ';
    private const REC_A = 'A → ';
    private const REC_AAAA = 'AAAA → ';

    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function platformDomain(): string
    {
        return (string) PlatformSetting::get('platform_domain', 'lms.example.com');
    }

    /** الرابط المجاني للمعلّم: /t/{slug} و {slug}.{platform_domain}. */
    private function slugPayload(Tenant $tenant): array
    {
        $platform = $this->platformDomain();
        $slug = $tenant->slug;

        return [
            'slug' => $slug,
            'platform_domain' => $platform,
            'page_url' => rtrim((string) config('app.frontend_url') ?: '', '/').'/t/'.$slug,
            'subdomain' => $slug.'.'.$platform,
            'subdomain_url' => 'https://'.$slug.'.'.$platform,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $features = app(FeatureService::class);
        $enabled = $features->hasFeature('custom_domain');

        $setting = TenantSetting::where('tenant_id', $request->current_tenant->id)->first();
        $domain = $setting?->custom_domain;
        $verified = (bool) ($setting?->metadata['domain_verified'] ?? false);

        // A domain is only ever SERVED (and only ever gets a certificate) once
        // it is verified: the edge asks `public/tls/ask` before issuing, and
        // that endpoint only allows verified domains. So `status` is the single
        // honest verdict the teacher sees.
        $status = $domain === null ? 'none' : ($verified ? 'active' : 'pending_dns');

        return response()->json([
            'enabled' => $enabled,
            'domain' => $domain,
            'verified' => $verified,
            'verified_at' => $setting?->metadata['domain_verified_at'] ?? null,
            'status' => $status,
            'https_enabled' => $verified,
            'site_url' => $domain ? 'https://'.$domain : null,
            'www_url' => $domain ? 'https://www.'.$domain : null,
            'platform_domain' => $this->platformDomain(),
            'dns_instructions' => $this->dnsInstructions($domain, $this->platformDomain()),
            ...$this->slugPayload($request->current_tenant),
        ]);
    }

    /** فحص توفّر رابط مطلوب قبل الحفظ (يحترم تشابه الأسماء والكلمات المحجوزة). */
    public function checkSlug(Request $request, TenantSlugService $slugs): JsonResponse
    {
        $this->ensureTeacher($request);

        $raw = (string) $request->query('slug', '');
        $slug = $slugs->normalize(preg_replace('/\s+/', '', $raw) ?? '');

        $reason = $slugs->unavailabilityReason($slug, $request->current_tenant->id);
        $available = $reason === null;
        $current = $slugs->normalize((string) $request->current_tenant->slug);

        return response()->json([
            'slug' => $slug,
            'available' => $available,
            'is_current' => $slug === $current,
            'reason' => $reason,
        ]);
    }

    /** حفظ رابط المعلّم الجديد. */
    public function updateSlug(Request $request, TenantSlugService $slugs): JsonResponse
    {
        $this->ensureTeacher($request);

        $validated = $request->validate([
            'slug' => 'required|string|max:80',
        ]);

        $slug = $slugs->normalize(preg_replace('/\s+/', '', $validated['slug']) ?? '');
        $tenant = $request->current_tenant;

        if ($slug === $slugs->normalize((string) $tenant->slug)) {
            return response()->json([
                'message' => 'هذا رابطك الحالي بالفعل.',
                ...$this->slugPayload($tenant),
            ]);
        }

        if (! $slugs->available($slug, $tenant->id)) {
            $messages = [
                'invalid' => 'الرابط يجب أن يكون بحروف إنجليزية أو أرقام أو شرطة (-) فقط، بطول 2 إلى 63 حرفاً.',
                'reserved' => 'هذا الاسم محجوز. اختر اسماً آخر.',
                'taken' => 'هذا الرابط غير متاح — اختر اسماً آخر.',
            ];

            return response()->json([
                'message' => $messages[$slugs->unavailabilityReason($slug) ?? 'taken'] ?? 'هذا الرابط غير متاح — اختر اسماً آخر.',
                'available' => false,
            ], 422);
        }

        $tenant->update(['slug' => $slug]);

        return response()->json([
            'message' => 'تم تحديث رابط منصتك.',
            ...$this->slugPayload($tenant->fresh()),
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $features = app(FeatureService::class);
        if (! $features->hasFeature('custom_domain')) {
            return response()->json(['message' => 'Custom domain is not included in your current plan.'], 403);
        }

        $validated = $request->validate([
            'domain' => 'required|string|max:190|regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
        ]);

        $domain = strtolower(rtrim($validated['domain'], '.'));

        // Store the APEX name only: `www.` is not a separate domain, it is a
        // variant of the same one, and lookup()/tls-ask already answer for
        // both `teacher.com` and `www.teacher.com`. Storing `www.` would make
        // the apex unserved and allow the same site to be claimed twice.
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        $platform = $this->platformDomain();

        if (str_contains($domain, $platform)) {
            return response()->json(['message' => 'This domain belongs to the platform itself.'], 422);
        }

        // A domain can only be claimed by one tenant — the DB unique index
        // would otherwise turn the duplicate into an uncaught 500. The lookup
        // must ignore the tenant scope: another tenant's row is exactly what
        // we are trying to see.
        $claimed = TenantSetting::withoutTenant()
            ->where('custom_domain', $domain)
            ->where('tenant_id', '!=', $request->current_tenant->id)
            ->exists();

        if ($claimed) {
            return response()->json(['message' => 'This domain is already connected to another platform.'], 422);
        }

        $setting = TenantSetting::firstOrCreate(
            ['tenant_id' => $request->current_tenant->id],
            ['id' => UuidV7::generate(), 'tenant_id' => $request->current_tenant->id]
        );

        $metadata = $setting->metadata ?? [];
        $metadata['domain_verified'] = false;
        $metadata['domain_verified_at'] = null;

        try {
            $setting->update([
                'custom_domain' => $domain,
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another tenant claiming the same name between
            // the check above and this write.
            return response()->json(['message' => 'This domain is already connected to another platform.'], 422);
        }

        return response()->json([
            'message' => 'Domain connected. Follow the DNS instructions below, then verify.',
            'domain' => $domain,
            'verified' => false,
            'dns_instructions' => $this->dnsInstructions($domain, $platform),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $setting = TenantSetting::where('tenant_id', $request->current_tenant->id)->first();
        if ($setting) {
            $metadata = $setting->metadata ?? [];
            unset($metadata['domain_verified'], $metadata['domain_verified_at']);
            $setting->update(['custom_domain' => null, 'metadata' => $metadata]);
        }

        return response()->json(['message' => 'Domain disconnected.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $setting = TenantSetting::where('tenant_id', $request->current_tenant->id)->first();
        $domain = $setting?->custom_domain;

        if (! $domain) {
            return response()->json(['message' => 'No domain connected yet.'], 422);
        }

        $platform = $this->platformDomain();
        $records = self::dnsRecordsFor($domain);
        $verified = self::recordsPointAtPlatform($records, $platform);

        if ($verified) {
            $metadata = $setting->metadata ?? [];
            $metadata['domain_verified'] = true;
            $metadata['domain_verified_at'] = now()->toIso8601String();
            $setting->update(['metadata' => $metadata]);
        }

        return response()->json([
            'verified' => $verified,
            'found_records' => $records,
            'message' => $verified
                ? 'Domain verified! Your site is live at https://'.$domain.' — an SSL certificate is issued automatically on the first visit (a few seconds).'
                : 'Not verified yet. Point the domain at the platform with a CNAME to '.$platform
                    .' (or an A record to '.self::platformIp($platform).'), then retry. DNS can take up to 24h to propagate.',
        ]);
    }

    /**
     * The DNS answers currently published for a domain, as human-readable
     * strings. Used by verify() so the teacher can see exactly what the
     * platform observed, and by the daily re-verification job.
     */
    public static function dnsRecordsFor(string $domain): array
    {
        $out = [];

        try {
            $cnames = @dns_get_record($domain, DNS_CNAME) ?: [];
            foreach ($cnames as $r) {
                $target = strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
                if ($target !== '') {
                    $out[] = self::REC_CNAME.$target;
                }
            }

            foreach (@dns_get_record($domain, DNS_A) ?: [] as $r) {
                $ip = (string) ($r['ip'] ?? '');
                if ($ip !== '') {
                    $out[] = self::REC_A.$ip;
                }
            }

            foreach (@dns_get_record($domain, DNS_AAAA) ?: [] as $r) {
                $ip = (string) ($r['ipv6'] ?? '');
                if ($ip !== '') {
                    $out[] = self::REC_AAAA.$ip;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique($out));
    }

    /** The platform's current public IPv4, used for apex A-record instructions. */
    public static function platformIp(string $platform): string
    {
        try {
            $ips = @gethostbynamel($platform) ?: [];
        } catch (\Throwable) {
            $ips = [];
        }

        return (string) ($ips[0] ?? 'server-ip-of-'.$platform);
    }

    /**
     * True when the published answers bind the domain to the platform: an
     * exact CNAME to the platform hostname, or an A/AAAA record that equals
     * the platform's own address (many registrars refuse a CNAME at the apex,
     * so the A record must be accepted too).
     *
     * $platformIps is injectable so callers/tests can supply the platform's
     * addresses without a live DNS lookup.
     */
    public static function recordsPointAtPlatform(array $records, string $platform, ?array $platformIps = null): bool
    {
        $wantHost = strtolower(rtrim($platform, '.'));
        $wantIps = [];

        if ($platformIps === null) {
            try {
                $wantIps = @gethostbynamel($platform) ?: [];
            } catch (\Throwable) {
                $wantIps = [];
            }

            foreach (@dns_get_record($platform, DNS_AAAA) ?: [] as $r) {
                if (! empty($r['ipv6'])) {
                    $wantIps[] = (string) $r['ipv6'];
                }
            }
        } else {
            $wantIps = $platformIps;
        }

        $wantIps = array_map('strtolower', array_filter($wantIps, 'is_string'));

        foreach ($records as $record) {
            if (str_starts_with($record, self::REC_CNAME)) {
                $target = strtolower(rtrim(substr($record, strlen(self::REC_CNAME)), '.'));
                if ($target === $wantHost) {
                    return true;
                }
            } elseif (str_starts_with($record, self::REC_A) || str_starts_with($record, self::REC_AAAA)) {
                $prefix = str_starts_with($record, self::REC_AAAA) ? self::REC_AAAA : self::REC_A;
                $ip = strtolower(trim(substr($record, strlen($prefix))));
                if ($ip !== '' && in_array($ip, $wantIps, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Live DNS check used by verify() and by the daily re-verification job so
     * a domain that was moved away is unverified again.
     */
    public static function dnsPointsAtPlatform(string $domain, string $platform): bool
    {
        return self::recordsPointAtPlatform(self::dnsRecordsFor($domain), $platform);
    }

    /**
     * Caddy's on-demand-TLS `ask` gate: it calls this before issuing a
     * certificate for a hostname. Only a VERIFIED custom domain (apex or
     * `www.`), a live platform subdomain, or the platform's own hosts are
     * allowed — anything else gets a 403 and no certificate.
     */
    public function tlsAsk(Request $request): \Illuminate\Http\Response
    {
        $host = strtolower(rtrim(trim((string) $request->query('domain', '')), ". \t"));

        if ($host === '' || strlen($host) > 190
            || ! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            return response('invalid', 403);
        }

        return self::isAllowedTlsHost($host, $this->platformDomain())
            ? response('ok', 200)
            : response('forbidden', 403);
    }

    /** Pure predicate behind tlsAsk() (unit-testable without HTTP). */
    public static function isAllowedTlsHost(string $host, string $platform): bool
    {
        $platform = strtolower(rtrim($platform, '.'));

        // The platform's own hosts always have explicit server blocks.
        if ($host === $platform || $host === 'www.'.$platform || $host === 'api.'.$platform) {
            return true;
        }

        // Teacher subdomain: {slug}.{platform}
        if (str_ends_with($host, '.'.$platform)) {
            $sub = substr($host, 0, -(strlen($platform) + 1));
            if ($sub !== '' && $sub !== 'www' && ! str_contains($sub, '.')
                && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sub)) {
                return Tenant::where('slug', $sub)->where('status', 'active')->exists();
            }

            return false;
        }

        // Verified custom domain, apex or www variant.
        $apex = preg_replace('/^www\./', '', $host) ?? $host;

        $setting = TenantSetting::withoutTenant()->where('custom_domain', $apex)->first();

        if (! $setting || ($setting->metadata['domain_verified'] ?? false) !== true) {
            return false;
        }

        // The setting must be the one that actually declares this exact host
        // (apex or its www variant) — never a different tenant's name.
        return $apex === $host || $host === 'www.'.$apex
            ? Tenant::whereKey($setting->tenant_id)->where('status', 'active')->exists()
            : false;
    }

    public function lookup(string $domain): JsonResponse
    {
        $domain = strtolower(trim($domain, '.'));

        // 1) Verified custom domain takes precedence — the apex name and its
        //    `www.` variant both resolve to the same tenant's site.
        $apex = preg_replace('/^www\./', '', $domain) ?? $domain;
        $tenantSetting = TenantSetting::withoutTenant()->where('custom_domain', $apex)->first();

        if ($tenantSetting && ($tenantSetting->metadata['domain_verified'] ?? false)) {
            $tenant = Tenant::find($tenantSetting->tenant_id);

            if ($tenant && $tenant->status === 'active') {
                return response()->json([
                    'site' => TeacherSiteController::sitePayload(
                        $tenant,
                        $tenantSetting,
                        $tenantSetting->metadata['site_config'] ?? []
                    ),
                ]);
            }
        }

        // 2) Teacher subdomain: {slug}.{platformDomain} — every active tenant
        //    gets a first-class address (e.g. teacher.fahimni.me) without
        //    claiming DNS or a database record. Exact-match first, so a custom
        //    domain is never shadowed by a same-named slug.
        $platform = $this->platformDomain();

        if ($domain !== $platform && $domain !== 'www.'.$platform && str_ends_with($domain, '.'.$platform)) {
            $sub = substr($domain, 0, -(strlen($platform) + 1));

            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $sub)) {
                $tenant = Tenant::where('slug', $sub)->where('status', 'active')->first();

                if ($tenant) {
                    $setting = TenantSetting::where('tenant_id', $tenant->id)->first();

                    return response()->json([
                        'site' => TeacherSiteController::sitePayload(
                            $tenant,
                            $setting,
                            $setting?->metadata['site_config'] ?? []
                        ),
                    ]);
                }
            }
        }

        return response()->json(['message' => 'Domain not found.'], 404);
    }

    private function dnsInstructions(?string $domain, string $platform): array
    {
        $ip = self::platformIp($platform);

        return [
            // Recommended: CNAME on `www` (works at every registrar).
            'cname_host' => 'www',
            'cname_value' => $platform,
            // Apex fallback: many registrars refuse a CNAME at `@`, so an A
            // record pointing at the platform's own address is accepted too.
            'a_host' => '@',
            'a_value' => $ip,
            'platform_ip' => $ip,
            'steps' => $domain ? [
                'افتح لوحة تحكم مزود الدومين (Namecheap / GoDaddy / Cloudflare / Hostinger...)',
                "أضف سجل CNAME: المضيف (Host) = www والقيمة (Value) = {$platform}",
                "أو — إن لم يدعم مزوّدك CNAME على الجذر — أضف سجل A: المضيف = @ والقيمة = {$ip}",
                'احفظ السجلات وانتظر من 5 دقائق إلى 24 ساعة لانتشار DNS',
                'اضغط «تحقق من الربط» — يتفعّل HTTPS تلقائيًا بعد نجاح التحقق',
            ] : [],
        ];
    }
}
