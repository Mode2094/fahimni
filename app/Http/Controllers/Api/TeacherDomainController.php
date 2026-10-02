<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherDomainController extends Controller
{
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

    public function show(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $features = app(FeatureService::class);
        $enabled = $features->hasFeature('custom_domain');

        $setting = TenantSetting::where('tenant_id', $request->current_tenant->id)->first();
        $domain = $setting?->custom_domain;

        return response()->json([
            'enabled' => $enabled,
            'domain' => $domain,
            'verified' => (bool) ($setting?->metadata['domain_verified'] ?? false),
            'verified_at' => $setting?->metadata['domain_verified_at'] ?? null,
            'platform_domain' => $this->platformDomain(),
            'dns_instructions' => $this->dnsInstructions($domain, $this->platformDomain()),
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
        $platform = $this->platformDomain();

        if (str_contains($domain, $platform)) {
            return response()->json(['message' => 'This domain belongs to the platform itself.'], 422);
        }

        // A domain can only be claimed by one tenant — the DB unique index
        // would otherwise turn the duplicate into an uncaught 500.
        $claimed = TenantSetting::where('custom_domain', $domain)
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

        $setting->update([
            'custom_domain' => $domain,
            'metadata' => $metadata,
        ]);

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
        $verified = self::dnsPointsAtPlatform($domain, $platform);

        if ($verified) {
            $metadata = $setting->metadata ?? [];
            $metadata['domain_verified'] = true;
            $metadata['domain_verified_at'] = now()->toIso8601String();
            $setting->update(['metadata' => $metadata]);
        }

        return response()->json([
            'verified' => $verified,
            'found_records' => $details ?? [],
            'message' => $verified
                ? 'Domain verified! Your site is live at https://'.$domain
                : 'Not verified yet. Make sure the CNAME record points to '.$platform.' (DNS may take up to 24h to propagate).',
        ]);
    }

    /**
     * Live DNS check: does an exact CNAME for the domain point at the
     * platform hostname? Used by verify() and by the daily re-verification
     * job so a domain that was moved away is unverified again.
     */
    public static function dnsPointsAtPlatform(string $domain, string $platform): bool
    {
        $records = [];

        try {
            $records = array_merge(
                @dns_get_record($domain, DNS_CNAME) ?: [],
                @dns_get_record($domain, DNS_A) ?: []
            );
        } catch (\Throwable) {
            return false;
        }

        foreach ($records as $r) {
            $target = strtolower((string) ($r['target'] ?? $r['ip'] ?? ''));

            if (($r['type'] ?? '') === 'CNAME' && rtrim($target, '.') === rtrim(strtolower($platform), '.')) {
                return true;
            }
        }

        return false;
    }

    public function lookup(string $domain): JsonResponse
    {
        $domain = strtolower(trim($domain, '.'));

        // 1) Verified custom domain takes precedence.
        $tenantSetting = TenantSetting::where('custom_domain', $domain)->first();

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
        //    gets a first-class address (e.g. teacher.tadreesly.com) without
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
        return [
            'cname_host' => $domain ? '@' : null,
            'cname_value' => $platform,
            'a_host' => $domain ? '@' : null,
            'a_value' => 'جملة الإرشادات حسب استضافة المنصة',
            'steps' => $domain ? [
                'افتح لوحة تحكم مزود الدومين الخاص بك (Namecheap / GoDaddy / Cloudflare...)',
                "أضف سجل CNAME جديد: المضيف (Host) = @ والقيمة (Value) = {$platform}",
                'احفظ السجل وانتظر 5 دقائق إلى 24 ساعة لانتشار DNS',
                'اضغط زر "تحقق من الربط" بالأسفل',
            ] : [],
        ];
    }
}
