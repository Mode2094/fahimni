<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\CloudflareSaasService;
use App\Services\FeatureService;
use App\Services\TenantDomainResolver;
use App\Services\TenantSlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        return app(TenantDomainResolver::class)->platformDomain();
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

        // The single honest verdict: a domain only ever SERVES once it is
        // verified — which, under Cloudflare for SaaS, means Cloudflare has
        // both validated the hostname and issued the edge certificate.
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
            'dns_instructions' => self::instructionsFor($domain, $this->platformDomain(), self::hostnameContext($setting)),
            'hostname' => self::hostnameContext($setting),
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
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        $platform = $this->platformDomain();

        if (str_contains($domain, $platform)) {
            return response()->json(['message' => 'This domain belongs to the platform itself.'], 422);
        }

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
        unset($metadata['domain_last_error']);

        try {
            $setting->update([
                'custom_domain' => $domain,
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'This domain is already connected to another platform.'], 422);
        }

        // Register the domain with Cloudflare for SaaS so TLS is provisioned and
        // the origin stays hidden behind the Cloudflare-only firewall.
        self::syncCustomHostname($setting->fresh());

        $setting = $setting->fresh();

        return response()->json([
            'message' => 'Domain connected. Follow the DNS instructions below, then verify.',
            'domain' => $domain,
            'verified' => false,
            'dns_instructions' => self::instructionsFor($domain, $platform, self::hostnameContext($setting)),
            'hostname' => self::hostnameContext($setting),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $setting = TenantSetting::where('tenant_id', $request->current_tenant->id)->first();

        if ($setting) {
            // Remove the Cloudflare custom hostname first so no certificate is
            // left dangling for a domain the tenant no longer owns.
            self::releaseCustomHostname($setting);

            $metadata = $setting->metadata ?? [];
            unset(
                $metadata['domain_verified'], $metadata['domain_verified_at'],
                $metadata['domain_last_error'], $metadata['cf_hostname_id'],
                $metadata['cf_status'], $metadata['cf_ssl_status'],
                $metadata['cf_ownership'], $metadata['cf_validation_records'],
                $metadata['cf_last_synced_at'],
                $metadata['cf_hostname_id_www'], $metadata['cf_status_www'],
                $metadata['cf_ssl_status_www'],
            );
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

        $liveHost = $domain;

        if (CloudflareSaasService::enabled()) {
            // Authoritative: Cloudflare validates the CNAME and issues the edge
            // certificate. No DNS lookups of our own are needed.
            $verified = self::syncCustomHostname($setting);
            $setting->refresh();

            $meta = $setting->metadata ?? [];
            $apexLive = ($meta['cf_status'] ?? null) === 'active' && ($meta['cf_ssl_status'] ?? null) === 'active';
            $wwwLive = ($meta['cf_status_www'] ?? null) === 'active' && ($meta['cf_ssl_status_www'] ?? null) === 'active';

            // Serve the host Cloudflare actually validated. The apex can be
            // legitimately pending when the registrar flattens it into A records,
            // while `www` is live.
            $liveHost = (! $apexLive && $wwwLive) ? 'www.'.$domain : $domain;

            $records = [
                'CNAME → '.(CloudflareSaasService::cnameTarget() ?: ''),
                'Cloudflare hostname: '.($meta['cf_status'] ?? 'pending'),
                'SSL: '.($meta['cf_ssl_status'] ?? 'pending'),
            ];
        } else {
            $platform = $this->platformDomain();
            $records = self::dnsRecordsFor($domain);
            $verified = self::recordsPointAtPlatform($records, $platform);
        }

        $metadata = $setting->metadata ?? [];

        if ($verified) {
            $metadata['domain_verified'] = true;
            $metadata['domain_verified_at'] = $metadata['domain_verified_at'] ?? now()->toIso8601String();
            unset($metadata['domain_last_error']);
        } else {
            $metadata['domain_verified'] = false;
        }

        $setting->update(['metadata' => $metadata]);

        return response()->json([
            'verified' => $verified,
            'found_records' => $records,
            'message' => $verified
                ? 'Domain verified! Your site is live at https://'.$liveHost.' with an SSL certificate managed by Cloudflare.'
                : ($metadata['domain_last_error']
                    ?? 'Not verified yet. Publish the DNS records below, then retry. DNS can take up to 24h to propagate.'),
            'hostname' => self::hostnameContext($setting->fresh()),
        ]);
    }

    /**
     * Ensure the Cloudflare custom hostnames exist and refresh their status into
     * the setting metadata. Returns true when the apex OR its `www.` sibling is
     * fully active (hostname + edge certificate) — the moment the domain is safe
     * to serve.
     *
     * Both hostnames are registered so the edge certificate covers whichever
     * host the visitor typed. Whichever one Cloudflare validates first makes the
     * tenant servable: a still-pending apex (which needs the Enterprise Apex
     * Proxying add-on once a registrar flattens it into A records) never blocks
     * `www`, and an as-yet-unpublished `www` never blocks a validated apex.
     */
    public static function syncCustomHostname(TenantSetting $setting): bool
    {
        $domain = (string) ($setting->custom_domain ?? '');

        if (! CloudflareSaasService::enabled() || $domain === '') {
            return false;
        }

        $metadata = $setting->metadata ?? [];

        try {
            $apexReady = self::syncOneHostname($metadata, $domain, 'cf_hostname_id', 'cf_status', 'cf_ssl_status');

            $wwwReady = false;

            if (! str_starts_with($domain, 'www.')) {
                try {
                    $wwwReady = self::syncOneHostname($metadata, 'www.'.$domain, 'cf_hostname_id_www', 'cf_status_www', 'cf_ssl_status_www');
                } catch (\Throwable $e) {
                    Log::warning('Cloudflare www custom hostname sync failed', [
                        'domain' => $domain,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $metadata['cf_last_synced_at'] = now()->toIso8601String();

            // A domain is servable when AT LEAST ONE of its hostnames (apex or
            // `www`) is fully active on Cloudflare. The apex can only validate
            // when the registrar keeps a real CNAME to the SaaS zone — Cloudflare
            // requires the Enterprise Apex Proxying add-on when the apex is
            // flattened into A records. A still-pending apex must therefore NEVER
            // block the working `www` host, or the whole tenant would be wrongly
            // marked unverified and its edge certificate withheld.
            $served = $apexReady || $wwwReady;

            if ($served) {
                unset($metadata['domain_last_error']);
            } else {
                $metadata['domain_last_error'] = self::pendingReason($metadata);
            }

            $setting->update(['metadata' => $metadata]);

            return $served;
        } catch (\Throwable $e) {
            $metadata['cf_last_synced_at'] = now()->toIso8601String();
            $metadata['domain_last_error'] = $e->getMessage();
            $setting->update(['metadata' => $metadata]);

            Log::warning('Cloudflare custom hostname sync failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Provision/refresh ONE custom hostname and fold its live status into
     * $metadata under the given id/status/ssl keys. Returns true when the
     * hostname and its certificate are both active.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function syncOneHostname(array &$metadata, string $name, string $idKey, string $statusKey, string $sslKey): bool
    {
        $id = $metadata[$idKey] ?? null;
        $host = $id ? CloudflareSaasService::getHostname((string) $id) : null;

        // Missing locally or removed in Cloudflare → reuse an existing one (so we
        // never hit a duplicate-hostname error) or create it. Creating only ever
        // happens when no hostname with this exact name exists, so the ownership
        // verification token is never rotated by a needless re-create.
        if (! $host) {
            $host = CloudflareSaasService::findHostnameByName($name);

            if (! $host) {
                try {
                    $host = CloudflareSaasService::createHostname($name);
                } catch (\Throwable $e) {
                    // Cloudflare refuses a duplicate name (e.g. a concurrent
                    // sync already created it). Adopt the existing hostname
                    // instead of failing — never delete and re-create, which
                    // would invalidate the domain's published ownership token.
                    $host = CloudflareSaasService::findHostnameByName($name);

                    if (! $host) {
                        throw $e;
                    }
                }
            }

            $metadata[$idKey] = $host['id'] ?? $id;
        }

        $metadata[$statusKey] = $host['status'] ?? null;
        $metadata[$sslKey] = $host['ssl']['status'] ?? null;

        // The ownership + DCV records belong to the apex hostname only.
        if ($idKey === 'cf_hostname_id') {
            $metadata['cf_ownership'] = $host['ownership_verification'] ?? ($metadata['cf_ownership'] ?? null);
            $metadata['cf_validation_records'] = $host['ssl']['validation_records'] ?? ($metadata['cf_validation_records'] ?? []);
        }

        return ($metadata[$statusKey] === 'active') && ($metadata[$sslKey] === 'active');
    }

    /** Best-effort removal of the Cloudflare custom hostname (and its cert). */
    public static function releaseCustomHostname(TenantSetting $setting): void
    {
        if (! CloudflareSaasService::enabled()) {
            return;
        }

        $metadata = $setting->metadata ?? [];

        foreach (['cf_hostname_id', 'cf_hostname_id_www'] as $key) {
            $id = $metadata[$key] ?? null;

            if (! $id) {
                continue;
            }

            try {
                CloudflareSaasService::deleteHostname((string) $id);
            } catch (\Throwable $e) {
                Log::warning('Cloudflare custom hostname delete failed', [
                    'domain' => $setting->custom_domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The Cloudflare state for a setting, surfaced to the UIs so both the
     * teacher and the Super Admin see the same, real provisioning status.
     *
     * @return array<string, mixed>
     */
    public static function hostnameContext(?TenantSetting $setting): array
    {
        $meta = $setting?->metadata ?? [];

        return [
            'enabled' => CloudflareSaasService::enabled(),
            'cname_target' => CloudflareSaasService::cnameTarget(),
            'hostname_id' => $meta['cf_hostname_id'] ?? null,
            'status' => $meta['cf_status'] ?? null,
            'ssl_status' => $meta['cf_ssl_status'] ?? null,
            'hostname_id_www' => $meta['cf_hostname_id_www'] ?? null,
            'www_status' => $meta['cf_status_www'] ?? null,
            'www_ssl_status' => $meta['cf_ssl_status_www'] ?? null,
            'ownership' => $meta['cf_ownership'] ?? null,
            'validation_records' => $meta['cf_validation_records'] ?? [],
            'last_synced_at' => $meta['cf_last_synced_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private static function pendingReason(array $metadata): string
    {
        $apex = $metadata['cf_status'] ?? null;
        $www = $metadata['cf_status_www'] ?? null;

        if ($apex !== 'active' && $www !== 'active') {
            return 'Cloudflare has not validated the hostname yet. Publish the CNAME record below and wait a few minutes.';
        }

        return 'DNS validated. The SSL certificate is being issued by Cloudflare — usually ready within a few minutes.';
    }

    /**
     * The DNS answers currently published for a domain, as human-readable
     * strings. Used by verify() (legacy direct mode) and by the re-verification
     * job so the platform can see exactly what the domain resolves to.
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
     * exact CNAME to the platform hostname or to the Cloudflare CNAME target,
     * or an A/AAAA record that equals the platform's own address (many
     * registrars refuse a CNAME at the apex, so the A record must be accepted
     * too).
     *
     * $platformIps is injectable so callers/tests can supply the platform's
     * addresses without a live DNS lookup.
     */
    public static function recordsPointAtPlatform(array $records, string $platform, ?array $platformIps = null): bool
    {
        $wantHosts = [strtolower(rtrim($platform, '.'))];

        $target = strtolower(rtrim(CloudflareSaasService::cnameTarget(), '.'));
        if ($target !== '') {
            $wantHosts[] = $target;
        }

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
                $t = strtolower(rtrim(substr($record, strlen(self::REC_CNAME)), '.'));
                if (in_array($t, $wantHosts, true)) {
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
     * Live DNS check used by the daily re-verification job (legacy direct mode)
     * so a domain that was moved away is unverified again.
     */
    public static function dnsPointsAtPlatform(string $domain, string $platform): bool
    {
        return self::recordsPointAtPlatform(self::dnsRecordsFor($domain), $platform);
    }

    /**
     * Caddy's on-demand-TLS `ask` gate. Under Cloudflare for SaaS the origin no
     * longer issues certificates for custom domains, but this gate is retained
     * as defence-in-depth: only a VERIFIED custom domain (apex or `www.`), a
     * live platform subdomain, or the platform's own hosts are ever blessed.
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

    /**
     * Pure predicate behind tlsAsk() (unit-testable without HTTP). Delegates to
     * the shared resolver so the edge gate, the site lookup and the session
     * trust middleware can never drift apart.
     */
    public static function isAllowedTlsHost(string $host, string $platform): bool
    {
        return app(TenantDomainResolver::class)->isAllowedEdgeHost($host, $platform);
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

    /**
     * The exact DNS records a teacher (or a support admin acting for them) must
     * publish — derived from the real production configuration, never inventing
     * an address.
     *
     * Under Cloudflare for SaaS the teacher needs a CNAME to the platform's
     * CNAME target; the `www` host always works. The apex only validates when
     * the registrar keeps a real CNAME to the SaaS zone — Cloudflare requires
     * its Enterprise Apex Proxying add-on once the apex is flattened into A
     * records (as Hostinger does). In legacy direct mode it falls back to the
     * classic CNAME-to-platform / apex-A instructions.
     *
     * @param  array<string, mixed>|null  $cf  {@see hostnameContext()}
     */
    public static function instructionsFor(?string $domain, string $platform, ?array $cf = null): array
    {
        $cfEnabled = (bool) ($cf['enabled'] ?? CloudflareSaasService::enabled());
        $target = trim((string) ($cf['cname_target'] ?? CloudflareSaasService::cnameTarget()));

        if ($cfEnabled && $target !== '') {
            return self::cloudflareInstructions($domain, $platform, $target, $cf ?? []);
        }

        return self::legacyInstructions($domain, $platform);
    }

    /**
     * @param  array<string, mixed>  $cf
     * @return array<string, mixed>
     */
    private static function cloudflareInstructions(?string $domain, string $platform, string $target, array $cf): array
    {
        $ownership = $cf['ownership'] ?? null;
        $validation = is_array($cf['validation_records'] ?? null) ? $cf['validation_records'] : [];

        $records = [];
        $records[] = ['type' => 'CNAME', 'host' => '@', 'value' => $target, 'purpose' => 'الجذر / apex'];
        $records[] = ['type' => 'CNAME', 'host' => 'www', 'value' => $target, 'purpose' => 'www'];

        $txtHost = null;
        $txtValue = null;

        if (is_array($ownership) && ! empty($ownership['name']) && ! empty($ownership['value'])) {
            $txtHost = (string) $ownership['name'];
            $txtValue = (string) $ownership['value'];
            // Required for the apex whenever the registrar flattens the apex
            // CNAME/ALIAS into A records (Hostinger, Namecheap ALIAS…): Cloudflare
            // then cannot match a CNAME and validates ownership from this TXT
            // instead. Without it the apex hostname stays `pending` forever.
            $records[] = ['type' => 'TXT', 'host' => $txtHost, 'value' => $txtValue, 'purpose' => 'تحقق الملكية (إلزامي للجذر)'];
        }

        foreach ($validation as $v) {
            if (is_array($v) && ! empty($v['txt_name']) && ! empty($v['txt_value'])) {
                $records[] = ['type' => 'TXT', 'host' => (string) $v['txt_name'], 'value' => (string) $v['txt_value'], 'purpose' => 'تحقق الشهادة (DCV)'];
            }
        }

        return [
            'mode' => 'cloudflare_saas',
            'cname_host' => '@',
            'cname_value' => $target,
            'www_host' => 'www',
            'www_value' => $target,
            // No A record is used under Cloudflare for SaaS — pointing an A
            // record at an address would bypass the edge and break TLS.
            'a_host' => null,
            'a_value' => null,
            'platform_ip' => null,
            'txt_host' => $txtHost,
            'txt_value' => $txtValue,
            'cname_target' => $target,
            'records' => $records,
            'apex_note' => 'العنوان الموثوق الذي يعمل دائمًا هو «www» (سجل CNAME نحو الهدف). أما الجذر (@) فيعمل عبر CNAME/ALIAS فقط إذا أبقى مزوّد الدومين سجل CNAME حقيقيًا؛ بعض المزوّدين (مثل Hostinger) يحوّلون الجذر إلى سجلات A فلا يتمكّن Cloudflare من التحقق منه، ويبقى الجذر بانتظار التحقق بينما يُخدَم موقعك على www. أضف سجل TXT للتحقق المُدرج بالأسفل وأبقِه حتى تصبح حالة الدومين «نشطة» (active).',
            'steps' => $domain ? [
                'افتح لوحة DNS لدى مزوّد الدومين (Hostinger / Namecheap / GoDaddy / Cloudflare...)',
                "أضف سجل CNAME للمضيف www والقيمة {$target} — هذا العنوان الذي يعمل دائمًا",
                "وللجذر @ أضف سجل CNAME/ALIAS بنفس القيمة {$target} (اختياري — يعمل فقط إذا أبقى مزوّدك سجل CNAME على الجذر)",
                $txtValue
                    ? "أضف سجل TXT للتحقق من الملكية: المضيف {$txtHost} والقيمة {$txtValue} — إلزامي عندما يسطّح المزوّد الجذر إلى سجلات A"
                    : 'انتظر بضع دقائق حتى يتحقق Cloudflare من الدومين تلقائيًا',
                'اضغط «تحقق من الربط» — يُصدر Cloudflare شهادة SSL تلقائيًا ويتجدد تلقائيًا',
            ] : [],
            'hostinger_steps' => $domain ? [
                'سجّل الدخول إلى hPanel على Hostinger ← Domains ← اختر الدومين',
                'افتح DNS / Nameservers ← Manage DNS records',
                "أضف سجل CNAME: Name = www ، Points to = {$target} ، TTL = 3600",
                "أضف سجل CNAME: Name = @ (يحوّله Hostinger تلقائيًا إلى ALIAS) ، Points to = {$target}",
                $txtValue
                    ? "أضف سجل TXT: Name = {$txtHost} ، Value = {$txtValue} (إلزامي للجذر @ — يتحقق Cloudflare من الملكية عبره)"
                    : 'احفظ التغييرات ثم اضغط «تحقق من الربط» هنا',
                'احفظ التغييرات ثم اضغط «تحقق من الربط» هنا',
            ] : [],
            'provider_note' => 'لا نحتاج حساب Cloudflare خاصًا بك ولا كلمة مرور مزوّد الدومين — تُدار سجلات DNS من حسابك، وتُدار الشهادة تلقائيًا من المنصة.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function legacyInstructions(?string $domain, string $platform): array
    {
        $ip = self::platformIp($platform);

        return [
            'mode' => 'direct',
            'cname_host' => 'www',
            'cname_value' => $platform,
            'a_host' => '@',
            'a_value' => $ip,
            'platform_ip' => $ip,
            'txt_host' => '@',
            'txt_value' => 'fahimni-verify='.$platform,
            'records' => [
                ['type' => 'CNAME', 'host' => 'www', 'value' => $platform, 'purpose' => 'www'],
                ['type' => 'A', 'host' => '@', 'value' => $ip, 'purpose' => 'الجذر / apex'],
            ],
            'steps' => $domain ? [
                'افتح لوحة تحكم مزود الدومين (Namecheap / GoDaddy / Cloudflare / Hostinger...)',
                "أضف سجل CNAME: المضيف (Host) = www والقيمة (Value) = {$platform}",
                "أو — إن لم يدعم مزوّدك CNAME على الجذر — أضف سجل A: المضيف = @ والقيمة = {$ip}",
                'احفظ السجلات وانتظر من 5 دقائق إلى 24 ساعة لانتشار DNS',
                'اضغط «تحقق من الربط» — يتفعّل HTTPS تلقائيًا بعد نجاح التحقق',
            ] : [],
            'hostinger_steps' => $domain ? [
                'سجّل الدخول إلى hPanel على Hostinger ← Domains ← اختر الدومين',
                'افتح DNS / Nameservers ← Manage DNS records',
                "أضف سجل CNAME: Name = www ، Points to = {$platform} ، TTL = 3600",
                "أو أضف سجل A: Name = @ ، Points to = {$ip} ، TTL = 3600",
                "أضف سجل TXT للتحقق من الملكية: Name = @ ، Value = fahimni-verify={$platform}",
                'احفظ التغييرات ثم اضغط «تحقق من الربط» هنا',
            ] : [],
            'provider_note' => 'لا نحتاج كلمة مرور Hostinger إطلاقًا — تُدار سجلات DNS من حسابك لديهم.',
        ];
    }
}
