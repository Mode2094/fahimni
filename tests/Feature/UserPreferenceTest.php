<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Per-teacher UI preferences live on the user row scoped by tenant. These tests
 * lock in the two safety properties: an unknown/secret key can never be stored,
 * and a preference saved under one tenant never leaks into another.
 */
class UserPreferenceTest extends TenantTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->teacher = $this->makeUser();
        $this->attachToTenant($this->teacher, $this->tenant, 'teacher');
        $this->attachSubscription($this->tenant);
    }

    public function test_teacher_saves_and_reads_html_editor_preferences(): void
    {
        $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->putJson('/api/v1/me/preferences', [
                'preferences' => [
                    'html_editor' => [
                        'height' => '700',
                        'grading_mode' => 'manual',
                        'grading_rule' => 'numeric',
                        'auto_capture' => true,
                    ],
                ],
            ])
            ->assertOk();

        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->getJson('/api/v1/me/preferences');

        $res->assertOk();
        $this->assertSame('700', $res->json('preferences.html_editor.height'));
        $this->assertSame('manual', $res->json('preferences.html_editor.grading_mode'));
        $this->assertTrue($res->json('preferences.html_editor.auto_capture'));
    }

    public function test_unknown_and_secret_keys_are_never_stored(): void
    {
        $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->putJson('/api/v1/me/preferences', [
                'preferences' => [
                    'html_editor' => ['height' => '500'],
                    // Both of these must be dropped.
                    'answer_key' => ['value' => 'SECRET'],
                    'html_editor_extra' => ['api_key' => 'super-secret'],
                ],
            ])
            ->assertOk();

        $this->teacher->refresh();
        $stored = $this->teacher->metadata['ui_preferences'][$this->tenant->id] ?? [];

        $this->assertSame(['html_editor'], array_keys($stored));
        $this->assertSame('500', $stored['html_editor']['height']);
        $this->assertStringNotContainsString('SECRET', json_encode($stored));
        $this->assertStringNotContainsString('super-secret', json_encode($this->teacher->metadata));
    }

    public function test_preferences_do_not_leak_across_tenants(): void
    {
        $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->putJson('/api/v1/me/preferences', [
                'preferences' => ['html_editor' => ['height' => '800']],
            ])
            ->assertOk();

        // Same teacher, a different tenant: their saved setup must not appear.
        $other = $this->makeTenant();
        $this->attachToTenant($this->teacher, $other, 'teacher');
        $this->attachSubscription($other);

        $res = $this->withHeaders($this->authHeaders($this->teacher, $other))
            ->getJson('/api/v1/me/preferences');

        $res->assertOk();
        $this->assertSame([], $res->json('preferences'));
    }
}
