<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\PaddleGateway;
use App\Services\PayPalGateway;
use App\Services\PayTabsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment gateway secrets must be encrypted at rest (Crypt) with a version
 * marker, readable during the migration transition, and never exposed.
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
        PlatformSetting::set('paddle_api_key', 'plain-api-key');
        PlatformSetting::set('paddle_webhook_secret', 'plain-webhook-secret');
        PlatformSetting::set('paytabs_server_key', ''); // empty stays empty

        $this->artisan('settings:encrypt-secrets')->assertExitCode(0);

        $first = PlatformSetting::get('paddle_api_key');
        $this->assertTrue(PlatformSetting::isEncryptedSecret($first));
        $this->assertSame('plain-api-key', PlatformSetting::getSecret('paddle_api_key'));
        $this->assertSame('plain-webhook-secret', PlatformSetting::getSecret('paddle_webhook_secret'));

        // Second run must not re-encrypt (ciphertext identical).
        $this->artisan('settings:encrypt-secrets')->assertExitCode(0);

        $this->assertSame($first, PlatformSetting::get('paddle_api_key'));
        $this->assertSame('plain-api-key', PlatformSetting::getSecret('paddle_api_key'));
    }

    public function test_gateways_read_decrypted_secrets(): void
    {
        PlatformSetting::setSecret('paypal_secret', 'pp-secret');
        PlatformSetting::setSecret('paytabs_server_key', 'pt-secret');
        PlatformSetting::setSecret('paddle_client_token', 'pd-client');
        PlatformSetting::setSecret('paddle_api_key', 'pd-api');
        PlatformSetting::setSecret('paddle_webhook_secret', 'pd-wh');

        $this->assertSame('pp-secret', PayPalGateway::config()['secret']);
        $this->assertSame('pt-secret', PayTabsGateway::config()['server_key']);

        $paddle = PaddleGateway::config();
        $this->assertSame('pd-client', $paddle['client_token']);
        $this->assertSame('pd-api', $paddle['api_key']);
        $this->assertSame('pd-wh', $paddle['webhook_secret']);
        $this->assertSame('pd-client', PaddleGateway::clientToken());
    }

    public function test_settings_response_never_returns_secret_values(): void
    {
        PlatformSetting::setSecret('paypal_secret', 'must-not-leak');
        PlatformSetting::setSecret('paddle_api_key', 'must-not-leak-either');

        $settings = app(\App\Http\Controllers\Api\PlatformSettingController::class)
            ->show(request())
            ->getData(true)['settings'];

        $this->assertSame('', $settings['paypal_secret']);
        $this->assertSame('', $settings['paddle_api_key']);
        $this->assertTrue($settings['paypal_secret_set']);
        $this->assertTrue($settings['paddle_api_key_set']);
        $this->assertStringNotContainsString('must-not-leak', json_encode($settings));
    }
}
