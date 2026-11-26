<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\TeacherDomainController as TeacherDomain;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantDomainResolver;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * Super-Admin management of a teacher's CUSTOM DOMAIN, from the teacher's admin
 * page — without having to sign in as the teacher.
 *
 * It reuses the EXISTING custom-domain machinery instead of rebuilding it:
 *  - the same `TenantSetting.custom_domain` + `metadata.domain_verified` store;
 *  - the same DNS verification helpers and on-demand-TLS allow-list
 *    ({@see TeacherDomain}, {@see TenantDomainResolver});
 *  - the same single-owner rule (one domain belongs to exactly one tenant).
 *
 * Every action is performed as an explicit admin action and audited with the
 * real admin identity. No Hostinger (or any registrar) credential is ever
 * requested or stored — the admin edits DNS in their own registrar panel.
 */
class TeacherDomainController extends Controller
{
    public function show(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);
        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();

        return response()->json($this->statusPayload($tenant, $setting));
    }

    public function connect(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);

        $validated = $request->validate([
            'domain' => 'required|string|max:190|regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
        ]);

        $domain = strtolower(rtrim($validated['domain'], '.'));
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        $platform = app(TenantDomainResolver::class)->platformDomain();

        if ($domain === '' || str_contains($domain, $platform)) {
            return response()->json(['message' => 'This domain belongs to the platform itself.'], 422);
        }

        // A domain can only be claimed by one tenant — check ignoring scope so
        // another tenant's claim is visible.
        $claimed = TenantSetting::withoutTenant()
            ->where('custom_domain', $domain)
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($claimed) {
            return response()->json(['message' => 'This domain is already connected to another platform.'], 422);
        }

        $setting = TenantSetting::withoutTenant()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenant->id],
        );

        $metadata = $setting->metadata ?? [];
        $metadata['domain_verified'] = false;
        $metadata['domain_verified_at'] = null;
        unset($metadata['domain_last_error']);

        try {
            $setting->update(['custom_domain' => $domain, 'metadata' => $metadata]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'This domain is already connected to another platform.'], 422);
        }

        AuditLogger::log($request, 'admin.teacher_domain.connect', 'tenant', $tenant->id, [
            'domain' => $domain,
        ], $tenant->id);

        return response()->json([
            'message' => 'Domain connected. Publish the DNS records below, then run the check.',
            'domain' => $domain,
            'verified' => false,
            'status' => 'pending_dns',
            ...$this->statusPayload($tenant, $setting->fresh()),
        ]);
    }

    public function verify(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);
        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();

        $domain = $setting?->custom_domain;

        if (! $domain) {
            return response()->json(['message' => 'No domain connected yet.'], 422);
        }

        $platform = app(TenantDomainResolver::class)->platformDomain();
        $records = TeacherDomain::dnsRecordsFor($domain);
        $verified = TeacherDomain::recordsPointAtPlatform($records, $platform);

        $metadata = $setting->metadata ?? [];

        if ($verified) {
            $metadata['domain_verified'] = true;
            $metadata['domain_verified_at'] = now()->toIso8601String();
            unset($metadata['domain_last_error']);
        } else {
            $metadata['domain_verified'] = false;
            $metadata['domain_last_error'] = 'DNS records do not point at the platform yet.';
            $metadata['domain_last_check_at'] = now()->toIso8601String();
        }

        $setting->update(['metadata' => $metadata]);

        AuditLogger::log($request, 'admin.teacher_domain.verify', 'tenant', $tenant->id, [
            'domain' => $domain,
            'verified' => $verified,
            'records' => $records,
        ], $tenant->id);

        return response()->json([
            'verified' => $verified,
            'found_records' => $records,
            'message' => $verified
                ? 'Domain verified — your site is live at https://'.$domain.' and an SSL certificate is issued automatically on the first visit.'
                : 'Not verified yet. Point the domain at '.$platform.' with the records below (DNS can take up to 24h), then retry.',
            ...$this->statusPayload($tenant, $setting->fresh(), $records),
        ]);
    }

    public function disconnect(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);
        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();

        $domain = $setting?->custom_domain;

        if ($setting) {
            $metadata = $setting->metadata ?? [];
            unset($metadata['domain_verified'], $metadata['domain_verified_at'], $metadata['domain_last_error'], $metadata['domain_last_check_at']);
            $setting->update(['custom_domain' => null, 'metadata' => $metadata]);
        }

        AuditLogger::log($request, 'admin.teacher_domain.disconnect', 'tenant', $tenant->id, [
            'domain' => $domain,
        ], $tenant->id);

        return response()->json([
            'message' => 'Domain disconnected. The teacher keeps the free platform link.',
            ...$this->statusPayload($tenant, $setting?->fresh()),
        ]);
    }

    /**
     * @param  list<string>|null  $foundRecords
     * @return array<string, mixed>
     */
    private function statusPayload(Tenant $tenant, ?TenantSetting $setting, ?array $foundRecords = null): array
    {
        $platform = app(TenantDomainResolver::class)->platformDomain();
        $domain = $setting?->custom_domain;
        $verified = (bool) ($setting?->metadata['domain_verified'] ?? false);
        $lastError = $setting?->metadata['domain_last_error'] ?? null;

        $status = match (true) {
            $domain === null => 'none',
            $verified => 'active',
            $lastError !== null => 'failed',
            default => 'pending_dns',
        };

        return [
            'teacher' => ['id' => $tenant->id, 'name' => $tenant->name],
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'domain' => $domain,
            'verified' => $verified,
            'verified_at' => $setting?->metadata['domain_verified_at'] ?? null,
            'status' => $status,
            'status_reason' => $lastError,
            'https_enabled' => $verified,
            'ssl' => $verified ? 'auto' : 'pending',
            'ssl_note' => 'يُصدر الشهادة تلقائيًا عند أول زيارة بعد نجاح التحقق من DNS.',
            'site_url' => $domain ? 'https://'.$domain : null,
            'www_url' => $domain ? 'https://www.'.$domain : null,
            'platform_domain' => $platform,
            'subdomain' => $tenant->slug.'.'.$platform,
            'subdomain_url' => 'https://'.$tenant->slug.'.'.$platform,
            'found_records' => $foundRecords,
            'dns_instructions' => TeacherDomain::instructionsFor($domain, $platform),
        ];
    }

    private function tenantForTeacher(string $teacher): Tenant
    {
        $user = User::role('Teacher')->with([
            'tenantPivots' => fn ($pivots) => $pivots->withoutTenant()->with('tenant'),
        ])->findOrFail($teacher);

        $tenant = $user->tenantPivots->first()?->tenant;

        abort_if(! $tenant, 422, 'This teacher has no workspace yet.');

        return $tenant;
    }
}
