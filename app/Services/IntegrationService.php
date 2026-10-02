<?php

namespace App\Services;

use App\Integrations\Contracts\Driver;
use App\Integrations\DiscordDriver;
use App\Integrations\DropboxDriver;
use App\Integrations\GoogleDriveDriver;
use App\Integrations\OneDriveDriver;
use App\Integrations\SlackDriver;
use App\Integrations\TelegramDriver;
use App\Integrations\VimeoDriver;
use App\Integrations\WhatsAppDriver;
use App\Integrations\YouTubeDriver;
use App\Integrations\ZapierDriver;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

class IntegrationService
{
    /**
     * Driver classes keyed by provider slug.
     *
     * @var array<string, class-string<Driver>>
     */
    public const DRIVERS = [
        'google_drive' => GoogleDriveDriver::class,
        'dropbox' => DropboxDriver::class,
        'onedrive' => OneDriveDriver::class,
        'youtube' => YouTubeDriver::class,
        'vimeo' => VimeoDriver::class,
        'telegram' => TelegramDriver::class,
        'whatsapp' => WhatsAppDriver::class,
        'discord' => DiscordDriver::class,
        'slack' => SlackDriver::class,
        'zapier' => ZapierDriver::class,
    ];

    /**
     * Per-provider UI metadata: label, category, OAuth endpoints, form fields.
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        return [
            'google_drive' => [
                'label' => 'Google Drive',
                'category' => 'storage',
                'description' => 'تصفح ملفات Google Drive واستيرادها إلى الدورات',
                'description_en' => 'Browse Google Drive files and import them into courses',
                'oauth' => true,
                'oauth_authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'oauth_scopes' => 'https://www.googleapis.com/auth/drive.readonly',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true, 'ltr' => true],
                    'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'required' => true, 'ltr' => true],
                ],
            ],
            'dropbox' => [
                'label' => 'Dropbox',
                'category' => 'storage',
                'description' => 'تصفح ملفات Dropbox واستيرادها إلى الدورات',
                'description_en' => 'Browse Dropbox files and import them into courses',
                'oauth' => true,
                'oauth_authorize' => 'https://www.dropbox.com/oauth2/authorize',
                'oauth_scopes' => 'files.content.read',
                'fields' => [
                    'client_id' => ['label' => 'App key', 'type' => 'text', 'required' => true, 'ltr' => true],
                    'client_secret' => ['label' => 'App secret', 'type' => 'password', 'required' => true, 'ltr' => true],
                ],
            ],
            'onedrive' => [
                'label' => 'OneDrive',
                'category' => 'storage',
                'description' => 'تصفح ملفات OneDrive واستيرادها إلى الدورات',
                'description_en' => 'Browse OneDrive files and import them into courses',
                'oauth' => true,
                'oauth_authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'oauth_scopes' => 'Files.Read.All offline_access User.Read',
                'fields' => [
                    'client_id' => ['label' => 'Application (client) ID', 'type' => 'text', 'required' => true, 'ltr' => true],
                    'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'required' => true, 'ltr' => true],
                ],
            ],
            'youtube' => [
                'label' => 'YouTube',
                'category' => 'video',
                'description' => 'رفع الفيديوهات إلى قناتك وربطها بالدورات',
                'description_en' => 'Upload videos to your channel and embed them in courses',
                'oauth' => true,
                'oauth_authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'oauth_scopes' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.upload',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true, 'ltr' => true],
                    'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'required' => true, 'ltr' => true],
                ],
            ],
            'vimeo' => [
                'label' => 'Vimeo',
                'category' => 'video',
                'description' => 'رفع الفيديوهات إلى Vimeo وربطها بالدورات',
                'description_en' => 'Upload videos to Vimeo and embed them in courses',
                'oauth' => true,
                'oauth_authorize' => 'https://api.vimeo.com/oauth/authorize',
                'oauth_scopes' => 'public upload',
                'fields' => [
                    'client_id' => ['label' => 'Client identifier', 'type' => 'text', 'required' => true, 'ltr' => true],
                    'client_secret' => ['label' => 'Client secret', 'type' => 'password', 'required' => true, 'ltr' => true],
                ],
            ],
            'telegram' => [
                'label' => 'Telegram',
                'category' => 'messaging',
                'description' => 'إرسال الإشعارات إلى قناة أو مجموعة عبر بوت',
                'description_en' => 'Send notifications to a channel or group via a bot',
                'oauth' => false,
                'fields' => [
                    'bot_token' => ['label' => 'Bot Token', 'type' => 'password', 'required' => true, 'ltr' => true, 'hint' => 'من @BotFather — مثال: 123456:ABC-...'],
                    'chat_id' => ['label' => 'Chat / Channel ID', 'type' => 'text', 'required' => true, 'ltr' => true, 'hint' => 'مثال: @my_channel أو -100123456789', 'hint_en' => 'e.g. @my_channel or -100123456789'],
                ],
            ],
            'whatsapp' => [
                'label' => 'WhatsApp Business',
                'category' => 'messaging',
                'description' => 'إرسال إشعارات WhatsApp عبر Cloud API',
                'description_en' => 'Send WhatsApp notifications via the Cloud API',
                'oauth' => false,
                'fields' => [
                    'access_token' => ['label' => 'Access Token', 'type' => 'password', 'required' => true, 'ltr' => true],
                    'phone_number_id' => ['label' => 'Phone Number ID', 'type' => 'text', 'required' => true, 'ltr' => true],
                ],
            ],
            'discord' => [
                'label' => 'Discord',
                'category' => 'messaging',
                'description' => 'إرسال الإشعارات إلى قناة عبر Webhook',
                'description_en' => 'Send notifications to a channel via webhook',
                'oauth' => false,
                'fields' => [
                    'webhook_url' => ['label' => 'Webhook URL', 'type' => 'text', 'required' => true, 'ltr' => true, 'hint' => 'Server Settings → Integrations → Webhooks'],
                ],
            ],
            'slack' => [
                'label' => 'Slack',
                'category' => 'messaging',
                'description' => 'إرسال الإشعارات إلى Slack عبر Webhook أو Bot',
                'description_en' => 'Send notifications to Slack via webhook or bot',
                'oauth' => false,
                'fields' => [
                    'webhook_url' => ['label' => 'Incoming Webhook URL', 'type' => 'text', 'required' => false, 'ltr' => true],
                    'bot_token' => ['label' => 'Bot Token (xoxb-...)', 'type' => 'password', 'required' => false, 'ltr' => true],
                    'channel' => ['label' => 'Channel (عند استخدام Bot)', 'type' => 'text', 'required' => false, 'ltr' => true, 'hint' => 'مثال: #general', 'hint_en' => 'e.g. #general'],
                ],
            ],
            'zapier' => [
                'label' => 'Zapier',
                'category' => 'webhook',
                'description' => 'ربط المنصة بأكثر من 6000 تطبيق عبر Zapier',
                'description_en' => 'Connect the platform to 6000+ apps via Zapier',
                'oauth' => false,
                'secret_auto' => true,
                'fields' => [
                    'events' => [
                        'label' => 'الأحداث المرسلة', 'type' => 'checkboxes', 'required' => false,
                        'options' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(ZapierDriver::EVENTS), ZapierDriver::EVENTS),
                    ],
                ],
            ],
        ];
    }

    /**
     * Setup instructions shown in the UI for each provider.
     *
     * @return array<string, array{app_url: string, redirect_uri: bool, steps: array<int, array{ar: string, en: string}>}>
     */
    public function guides(): array
    {
        return [
            'google_drive' => [
                'app_url' => 'https://console.cloud.google.com/apis/credentials',
                'redirect_uri' => true,
                'steps' => [
                    ['ar' => 'افتح Google Cloud Console → APIs & Services', 'en' => 'Open Google Cloud Console → APIs & Services'],
                    ['ar' => 'فعّل Google Drive API وأنشئ OAuth Client ID (Web App)', 'en' => 'Enable the Google Drive API and create an OAuth Client ID (Web App)'],
                    ['ar' => 'أضف رابط التوجيه الظاهر أدناه في Authorized redirect URIs', 'en' => 'Add the redirect URI below in Authorized redirect URIs'],
                    ['ar' => 'ضع البيانات ثم اضغط ربط الحساب وسجّل دخولك بحساب Google', 'en' => 'Enter the credentials, click Connect account and sign in with Google'],
                ],
            ],
            'dropbox' => [
                'app_url' => 'https://www.dropbox.com/developers/apps',
                'redirect_uri' => true,
                'steps' => [
                    ['ar' => 'افتح Dropbox App Console → Create app', 'en' => 'Open Dropbox App Console → Create app'],
                    ['ar' => 'اختر Scoped access → Full Dropbox ثم أنشئ التطبيق', 'en' => 'Choose Scoped access → Full Dropbox, then create the app'],
                    ['ar' => 'من Permissions فعّل files.content.read وأضف رابط التوجيه في Redirect URIs', 'en' => 'Under Permissions enable files.content.read and add the redirect URI'],
                    ['ar' => 'انسخ App key و App secret وضعهما هنا ثم ربط الحساب', 'en' => 'Copy the App key & App secret, then connect the account'],
                ],
            ],
            'onedrive' => [
                'app_url' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
                'redirect_uri' => true,
                'steps' => [
                    ['ar' => 'افتح Microsoft Entra → App registrations → New registration', 'en' => 'Open Microsoft Entra → App registrations → New registration'],
                    ['ar' => 'أضف رابط التوجيه الظاهر أدناه (Web) وأضف صلاحية Files.Read.All من API permissions', 'en' => 'Add the redirect URI below (Web) and grant Files.Read.All under API permissions'],
                    ['ar' => 'انسخ Application ID وأنشئ Client Secret', 'en' => 'Copy the Application ID and create a Client Secret'],
                ],
            ],
            'youtube' => [
                'app_url' => 'https://console.cloud.google.com/apis/credentials',
                'redirect_uri' => true,
                'steps' => [
                    ['ar' => 'افتح Google Cloud Console → فعّل YouTube Data API v3', 'en' => 'Open Google Cloud Console → enable YouTube Data API v3'],
                    ['ar' => 'أنشئ OAuth Client ID (Web App) وأضف رابط التوجيه الظاهر أدناه', 'en' => 'Create an OAuth Client ID (Web App) and add the redirect URI shown below'],
                    ['ar' => 'أضف Users type = External وضع البيانات ثم ربط الحساب بقناتك', 'en' => 'Set Users type to External, add the credentials, then connect your channel'],
                ],
            ],
            'vimeo' => [
                'app_url' => 'https://developer.vimeo.com/apps',
                'redirect_uri' => true,
                'steps' => [
                    ['ar' => 'افتح Vimeo Developer → New app', 'en' => 'Open Vimeo Developer → New app'],
                    ['ar' => 'في إعدادات التطبيق أضف رابط التوجيه الظاهر أدناه في App URL / Redirect', 'en' => 'In the app settings add the redirect URI below'],
                    ['ar' => 'انسخ Client identifier و Client secret ثم ربط الحساب', 'en' => 'Copy the Client identifier & Client secret, then connect'],
                ],
            ],
            'telegram' => [
                'app_url' => 'https://t.me/BotFather',
                'redirect_uri' => false,
                'steps' => [
                    ['ar' => 'افتح @BotFather في تيليجرام وأرسل /newbot لإنشاء بوت', 'en' => 'Open @BotFather on Telegram and send /newbot to create a bot'],
                    ['ar' => 'انسخ الـ Token الذي يرسله لك (مثال: 123456:ABC-...)', 'en' => 'Copy the token it sends you (e.g. 123456:ABC-...)'],
                    ['ar' => 'أضف البوت إلى قناتك كمدير، وانسخ معرف القناة مثل @my_channel', 'en' => 'Add the bot as admin to your channel and copy its ID like @my_channel'],
                ],
            ],
            'whatsapp' => [
                'app_url' => 'https://developers.facebook.com/apps',
                'redirect_uri' => false,
                'steps' => [
                    ['ar' => 'افتح Meta for Developers → أنشئ تطبيق من نوع Business', 'en' => 'Open Meta for Developers → create a Business app'],
                    ['ar' => 'أضف منتج WhatsApp ثم اربط رقم هاتف عبر WhatsApp Cloud API', 'en' => 'Add the WhatsApp product and link a phone number via the WhatsApp Cloud API'],
                    ['ar' => 'انسخ Access Token و Phone Number ID من صفحة WhatsApp → API Setup', 'en' => 'Copy the Access Token and Phone Number ID from WhatsApp → API Setup'],
                ],
            ],
            'discord' => [
                'app_url' => 'https://discord.com/channels/@me',
                'redirect_uri' => false,
                'steps' => [
                    ['ar' => 'افتح إعدادات السيرفر → Integrations → Webhooks → New Webhook', 'en' => 'Open server settings → Integrations → Webhooks → New Webhook'],
                    ['ar' => 'انسخ Webhook URL والصقه هنا', 'en' => 'Copy the Webhook URL and paste it here'],
                ],
            ],
            'slack' => [
                'app_url' => 'https://api.slack.com/apps',
                'redirect_uri' => false,
                'steps' => [
                    ['ar' => 'افتح api.slack.com → Create New App → From scratch', 'en' => 'Open api.slack.com → Create New App → From scratch'],
                    ['ar' => 'فعّل Incoming Webhooks وأنشئ Webhook للقناة المطلوبة وانسخ الرابط', 'en' => 'Enable Incoming Webhooks, create a webhook for your channel and copy the URL'],
                    ['ar' => 'أو أنشئ Bot token بصلاحية chat:write وضع رقم القناة مثل #general', 'en' => 'Or create a Bot token with chat:write and set the channel like #general'],
                ],
            ],
            'zapier' => [
                'app_url' => 'https://zapier.com/app/editor',
                'redirect_uri' => false,
                'steps' => [
                    ['ar' => 'افتح Zapier → Create Zap واختر Webhooks by Zapier → Catch Hook', 'en' => 'Open Zapier → Create Zap and choose Webhooks by Zapier → Catch Hook'],
                    ['ar' => 'الصق رابط الويب هوك الخاص بك (الظاهر في البطاقة) كـ URL', 'en' => 'Paste your webhook URL (shown on the card) as the URL'],
                    ['ar' => 'اختر الأحداث المطلوبة من الأعلى ثم فعّل التكامل', 'en' => 'Select the events you need above, then enable the integration'],
                    ['ar' => 'كل حدث جديد (طالب، دفع، بث...) سيُرسل إلى Zap فوراً', 'en' => 'Every new event (student, payment, live...) is sent to your Zap instantly'],
                ],
            ],
        ];
    }

    public function isOAuth(string $provider): bool
    {
        return (bool) ($this->providers()[$provider]['oauth'] ?? false);
    }

    public function get(string $provider, ?string $tenantId): ?Integration
    {
        // withoutTenant(): integrations may be tenant-scoped OR platform-wide
        // (tenant_id NULL), so the global tenant scope must not apply here.
        $query = Integration::withoutTenant();

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        return $query->where('provider', $provider)->first();
    }

    public function getOrCreate(string $provider, ?string $tenantId): Integration
    {
        return $this->get($provider, $tenantId) ?? Integration::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'enabled' => false,
            'config' => [],
            'metadata' => [],
        ]);
    }

    public function driver(string $provider, ?string $tenantId): Driver
    {
        $class = self::DRIVERS[$provider] ?? null;

        if (! $class) {
            throw new \InvalidArgumentException("Unknown integration provider: {$provider}");
        }

        return new $class($this->getOrCreate($provider, $tenantId));
    }

    public function driverFor(Integration $integration): Driver
    {
        $class = self::DRIVERS[$integration->provider] ?? null;

        if (! $class) {
            throw new \InvalidArgumentException("Unknown integration provider: {$integration->provider}");
        }

        return new $class($integration);
    }

    /**
     * Save credentials for a provider.
     */
    public function save(string $provider, array $credentials, ?string $tenantId): Integration
    {
        $integration = $this->getOrCreate($provider, $tenantId);

        $integration->fillCredentials($credentials);
        $integration->connected_at = $integration->connected_at ?? now();
        $integration->save();

        return $integration;
    }

    /**
     * Test a provider connection and record the result.
     */
    public function test(string $provider, ?string $tenantId): array
    {
        $integration = $this->get($provider, $tenantId);

        if (! $integration) {
            return ['ok' => false, 'message' => 'Integration is not configured.'];
        }

        $driver = $this->driverFor($integration);
        $result = $driver->test();

        $integration->update([
            'last_tested_at' => now(),
            // Never persist secret-bearing details (Zapier endpoints embed
            // the webhook secret).
            'last_test_result' => $provider === 'zapier' ? $this->sanitizeTestResult($result) : $result,
        ]);

        return $result;
    }

    public function isEnabled(string $provider, ?string $tenantId): bool
    {
        $integration = $this->get($provider, $tenantId);

        return $integration?->enabled && $integration->isConfigured();
    }

    /**
     * Full provider list with connection status for the UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusList(?string $tenantId, bool $revealZapierSecret = false): array
    {
        $guides = $this->guides();

        return collect($this->providers())
            ->map(function ($meta, $provider) use ($tenantId, $guides, $revealZapierSecret) {
                $integration = $this->get($provider, $tenantId);

                return [
                    'provider' => $provider,
                    'label' => $meta['label'],
                    'category' => $meta['category'],
                    'description' => $meta['description'],
                    'description_en' => $meta['description_en'],
                    'oauth' => $meta['oauth'],
                    'fields' => $meta['fields'],
                    'guide' => $guides[$provider] ?? null,
                    'enabled' => (bool) ($integration?->enabled),
                    'configured' => (bool) ($integration?->isConfigured()),
                    'connected_at' => $integration?->connected_at?->toIso8601String(),
                    'last_tested_at' => $integration?->last_tested_at?->toIso8601String(),
                    // The test result is sanitized before storage; a stored
                    // endpoint would leak the Zapier webhook secret to any
                    // staff member who opens this list.
                    'last_test_result' => $integration && $provider === 'zapier'
                        ? $this->sanitizeTestResult($integration->last_test_result)
                        : $integration?->last_test_result,
                    // The webhook secret is embedded in the endpoint URL —
                    // only the tenant owner/admin may see it, never staff.
                    'endpoint' => $integration && $provider === 'zapier' && $revealZapierSecret
                        ? (new ZapierDriver($integration))->endpoint()
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    public function disconnect(string $provider, ?string $tenantId): bool
    {
        $integration = $this->get($provider, $tenantId);
        if (! $integration) {
            return false;
        }

        $integration->update([
            'enabled' => false,
            'credentials' => null,
            'connected_at' => null,
        ]);

        return true;
    }

    /**
     * Strip anything that could embed a secret (endpoints, URLs) from a
     * persisted test result before it is exposed to staff.
     */
    private function sanitizeTestResult(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result;
        }

        $clean = $result;
        unset($clean['details'], $clean['endpoint'], $clean['url'], $clean['target']);

        if (isset($clean['message'])) {
            $clean['message'] = preg_replace('#https?://\S+#', '[redacted]', (string) $clean['message']);
        }

        return $clean;
    }

    /**
     * Fire a Zapier event (best-effort, only if subscribed & enabled).
     */
    public function triggerZapier(?string $tenantId, string $event, array $data = []): void
    {
        if (! $tenantId) {
            return;
        }

        try {
            $integration = $this->get('zapier', $tenantId);

            if (! $integration?->enabled || ! $integration->isConfigured()) {
                return;
            }

            $driver = $this->driverFor($integration);

            if ($driver instanceof ZapierDriver) {
                $driver->trigger($event, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Zapier trigger failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
