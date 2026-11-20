<?php

use App\Models\TenantSetting;
use App\Support\StorefrontConfigCleaner;
use Illuminate\Database\Migrations\Migration;

/**
 * Remove the platform's fabricated stats / testimonial / FAQ that older
 * editor flows persisted into a teacher's stored site_config.
 *
 * Only exact matches to the known seed fingerprints are dropped, so a
 * teacher's own numbers, quotes and questions survive untouched. Not
 * reversible on purpose: the removed values were never real, and the
 * pre-deploy database backup is the recovery path.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSetting::withoutGlobalScope('tenant')
            ->chunkById(100, function ($settings) {
                foreach ($settings as $setting) {
                    $metadata = $setting->metadata ?? [];
                    $config = $metadata['site_config'] ?? null;

                    if (! is_array($config)) {
                        continue;
                    }

                    $result = StorefrontConfigCleaner::clean($config);

                    if (! $result['changed']) {
                        continue;
                    }

                    $metadata['site_config'] = $result['config'];
                    $setting->metadata = $metadata;
                    $setting->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring the fabricated data would put
        // invented numbers back on live teacher pages.
    }
};
