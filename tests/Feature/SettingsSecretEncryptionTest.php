<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\PayPalGateway;
use App\Services\PayTabsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Booking-gateway secrets must be encrypted at rest (Crypt) with a version
 * marker, readable during the migration transition, and never exposed.
 *
 * Freemius secrets (api token / signing secret) live ONLY in the environment
 * and are never stored in the database — only booking-gateway secrets
 * (paytabs / paypal) are persisted here.
 */
class SettingsSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_stored_encrypted_and_readable(): void
    {
        PlatformSetting::setSecret('paytabs_server_key', 'sk_live_super_secret');

        $raw = PlatformSetting::get('paytabs_server_key');

        $this->assertIsString($raw);
        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertStringNotContainsString('sk_live_super_secret', $raw);

        $this->assertSame('sk_live_super_secret', PlatformSetting::getSecret('paytabs_server_key'));
    }

    public function test_legacy_plaintext_remains_readable_before_migration(): void
    {
        PlatformSetting::set('paypal_secret', 'legacy-plaintext-secret');

        $this->assertSame('legacy-plaintext-secret', PlatformSetting::getSecret('paypal_secret'));
    }

    public function test_migration_command_encrypts_once_and_is_idempotent(): void
    {
        PlatformSetting::set('paypal_secret', 'plain-api-key');
        PlatformSetting::set('paytabs_server_key', ''); // empty stays empty

        $this->artisan('settings:encrypt-secrets')->assertExitCode(0);

        $first = PlatformSetting::get('paypal_secret');
        $this->assertTrue(PlatformSetting::isEncryptedSecret($first));
        $this->assertSame('plain-api-key', PlatformSetting::getSecret('paypal_secret'));

        // Second run must not re-encrypt (ciphertext identical).
        $this->artisan('settings:encrypt-secrets')->assertExitCode(0);

        $this->assertSame($first, PlatformSetting::get('paypal_secret'));
        $this->assertSame('plain-api-key', PlatformSetting::getSecret('paypal_secret'));
    }

    public function test_gateways_read_decrypted_secrets(): void
    {
        PlatformSetting::setSecret('paypal_secret', 'pp-secret');
        PlatformSetting::setSecret('paytabs_server_key', 'pt-secret');

        $this->assertSame('pp-secret', PayPalGateway::config()['secret']);
        $this->assertSame('pt-secret', PayTabsGateway::config()['server_key']);
    }

    public function test_settings_response_never_returns_secret_values(): void
    {
        PlatformSetting::setSecret('paypal_secret', 'must-not-leak');
        PlatformSetting::setSecret('paytabs_server_key', 'must-not-leak-either');

        $settings = app(\App\Http\Controllers\Api\PlatformSettingController::class)
            ->show(request())
            ->getData(true)['settings'];

        $this->assertSame('', $settings['paypal_secret']);
        $this->assertSame('', $settings['paytabs_server_key']);
        $this->assertTrue($settings['paypal_secret_set']);
        $this->assertTrue($settings['paytabs_server_key_set']);
        $this->assertStringNotContainsString('must-not-leak', json_encode($settings));
    }

    public function test_freemius_secrets_are_never_returned_from_settings(): void
    {
        // Freemius secrets live in the environment; the settings payload must
        // only ever expose presence booleans, never the values.
        $settings = app(\App\Http\Controllers\Api\PlatformSettingController::class)
            ->show(request())
            ->getData(true)['settings'];

        $this->assertArrayHasKey('freemius_api_token_set', $settings);
        $this->assertArrayHasKey('freemius_secret_key_set', $settings);
        $this->assertArrayNotHasKey('freemius_api_token', $settings);
        $this->assertArrayNotHasKey('freemius_secret_key', $settings);
    }
}
