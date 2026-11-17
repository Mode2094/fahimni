<?php

namespace App\Console\Commands;

use App\Services\FreemiusGateway;
use App\Services\FreemiusPlanProvisioner;
use Illuminate\Console\Command;

/**
 * Provision the Freemius plans + single USD pricing that back the local tiers.
 *
 * Default is DRY-RUN: it lists the existing Freemius plans, reports which tiers
 * would be created/linked, and makes NO changes. Pass --apply to actually create
 * the missing plans, create one USD pricing (monthly+annual) per tier, read the
 * real Plan/Pricing IDs back, link them to the local plans, and verify the price
 * + cycle match.
 *
 * Safe to run repeatedly: existing plans/pricings are reused, never duplicated.
 */
class FreemiusProvisionPlans extends Command
{
    protected $signature = 'freemius:provision-plans
        {--apply : Actually create/link plans and pricing on Freemius (default: dry-run)}
        {--tier=* : Restrict to specific local tier slugs (starter, professional, elite)}';

    protected $description = 'Dry-run (or --apply) the creation + linking of the Freemius plans/pricing that back the local subscription tiers.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = array_values(array_filter(array_map('strval', (array) $this->option('tier'))));
        $config = FreemiusGateway::config();

        $readReady = $config['product_id'] !== '' && $config['api_token'] !== '';
        $writeReady = FreemiusGateway::developerConfigured();

        $this->line($apply ? '<fg=yellow>MODE: APPLY</> — plans/pricing will be created and linked on Freemius.' : '<fg=green>MODE: DRY-RUN</> — no changes are made.');
        $this->line('Product read credentials (product_id + api_token): '.($readReady ? '<fg=green>OK</>' : '<fg=red>MISSING</>'));
        $this->line('Developer write credentials: '.($writeReady ? '<fg=green>OK</>' : '<fg=red>MISSING</>').' — developer secret-key auth (live route /plugins/)');
        $this->newLine();

        if (! $readReady) {
            $this->error('Cannot list/create plans: set FREEMIUS_PRODUCT_ID and FREEMIUS_API_TOKEN first.');

            return self::FAILURE;
        }

        if ($apply && ! $writeReady) {
            $this->error('Cannot create/link plans: set FREEMIUS_DEVELOPER_ID + FREEMIUS_DEVELOPER_PUBLIC_KEY + FREEMIUS_DEVELOPER_SECRET_KEY (Dashboard → My Profile → Keys).');

            return self::FAILURE;
        }

        $report = (new FreemiusPlanProvisioner)->provision($apply, $only);

        $this->table(
            ['Tier', 'Status', 'Plan ID', 'Pricing ID', 'Monthly', 'Annual', 'OK'],
            array_map(static fn (array $r): array => [
                (string) $r['tier'],
                (string) $r['status'],
                (string) ($r['plan_id'] ?? '—'),
                (string) ($r['pricing_id'] ?? '—'),
                $r['monthly'] !== null ? number_format((float) $r['monthly'], 2) : '—',
                $r['annual'] !== null ? number_format((float) $r['annual'], 2) : '—',
                $r['ok'] ? 'yes' : 'NO',
            ], $report['tiers']),
        );

        foreach ($report['tiers'] as $row) {
            $this->line(($row['ok'] ? '<fg=green>✔</>' : '<fg=red>✘</>')." {$row['tier']}: {$row['detail']}");
        }

        $this->newLine();

        if (! $report['ok']) {
            $this->error('Some tiers need attention — review the rows above. Nothing was displayed to customers.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry-run complete. Re-run with --apply to create and link the missing plans/pricing.');
        } else {
            $this->info('Provisioning complete: the plans/pricing are linked and the prices are verified.');
        }

        return self::SUCCESS;
    }
}
