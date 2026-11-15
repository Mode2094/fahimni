<?php

namespace Tests\Feature;

use App\Support\FeatureCatalog;
use Tests\TestCase;

/**
 * The plan/feature catalogue must never advertise a capability the platform
 * cannot deliver. Google Meet has no automatic recording API, so promising
 * "live recording" as a plan feature (and letting MediaInitCommand enable it on
 * every plan) was a false claim: the teacher attaches a recording link by hand
 * instead, and that path is not gated.
 */
class RecordingClaimsTest extends TestCase
{
    public function test_the_catalog_does_not_advertise_automatic_live_recording(): void
    {
        $catalog = collect(FeatureCatalog::all())->keyBy('key');

        $this->assertFalse(
            $catalog->has('live_recording_enabled'),
            'the platform must not sell a "live recording" feature it cannot deliver'
        );

        $blob = json_encode(FeatureCatalog::all(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('تسجيل البث تلقائياً', $blob);
        $this->assertStringNotContainsString('Automatically record live streams', $blob);
    }

    public function test_the_recordings_limit_is_described_as_attached_links_not_stored_media(): void
    {
        $entry = collect(FeatureCatalog::all())->firstWhere('key', 'recordings_limit');

        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('المحفوظة', $entry['desc_ar']);
        $this->assertStringNotContainsString('stored live recordings', $entry['desc_en']);
    }
}
