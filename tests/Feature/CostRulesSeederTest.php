<?php

namespace Tests\Feature;

use App\Models\ProviderCostRule;
use Database\Seeders\CostRulesSeeder;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * The provider cost rules (Bunny/Cloudflare/email/sms) used by the cost engine
 * must be seeded on a fresh install. They were defined but the seeder was never
 * registered in DatabaseSeeder, so a new environment had no cost baseline.
 */
class CostRulesSeederTest extends TestCase
{
    public function test_cost_rules_seeder_creates_the_bunny_baseline(): void
    {
        $this->seed(CostRulesSeeder::class);

        $this->assertDatabaseHas('provider_cost_rules', ['provider' => 'bunny', 'resource' => 'storage']);
        $this->assertDatabaseHas('provider_cost_rules', ['provider' => 'bunny', 'resource' => 'delivery']);
        $this->assertGreaterThanOrEqual(8, ProviderCostRule::count());
    }

    public function test_database_seeder_registers_the_cost_rules_seeder(): void
    {
        // Running the top-level seeder must produce provider cost rules, proving
        // CostRulesSeeder is wired in (regression: it used to be orphaned).
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, ProviderCostRule::count());
        $this->assertDatabaseHas('provider_cost_rules', ['provider' => 'bunny', 'resource' => 'delivery']);
    }

    public function test_running_the_seeder_twice_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = ProviderCostRule::count();
        $ids = ProviderCostRule::orderBy('id')->pluck('id')->all();

        // A re-run (e.g. a repeat deploy) must not duplicate rules nor mint new
        // primary keys for rows that already exist.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, ProviderCostRule::count());
        $this->assertSame($ids, ProviderCostRule::orderBy('id')->pluck('id')->all());
    }
}
