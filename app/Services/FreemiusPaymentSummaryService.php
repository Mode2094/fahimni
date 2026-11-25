<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Read-only, PII-free summary of the platform's Freemius payment ledger.
 *
 * The application does not mirror Freemius payments into `payments` (that
 * table is for student-to-teacher settlements), so subscription revenue must
 * come from the Merchant of Record. Results are cached briefly to avoid
 * paging the provider separately for every Super Admin page request.
 */
class FreemiusPaymentSummaryService
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 100;

    private const CACHE_SECONDS = 60;

    public function summary(): array
    {
        $config = FreemiusGateway::config();
        $cacheKey = 'super-admin:freemius-payments:v1:'.($config['product_id'] ?: 'unconfigured');

        try {
            return Cache::remember(
                $cacheKey,
                now()->addSeconds(self::CACHE_SECONDS),
                fn () => $this->fetchCompleteSummary($config),
            );
        } catch (\Throwable) {
            // A provider outage must never be presented as zero revenue.
            return $this->unavailable('provider_error');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fetchCompleteSummary(array $config): array
    {
        if (! ($config['enabled'] ?? false)
            || ($config['api_token'] ?? '') === ''
            || ($config['product_id'] ?? '') === '') {
            return $this->unavailable('not_configured');
        }

        $seen = [];
        $totals = [];
        $offset = 0;
        $complete = false;

        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $payments = FreemiusGateway::listPayments($offset, self::PAGE_SIZE);

                if (count($payments) > self::PAGE_SIZE) {
                    return $this->unavailable('invalid_page_size');
                }

                foreach ($payments as $payment) {
                    if (! is_array($payment) || ! $this->isValidPayment($payment)) {
                        return $this->unavailable('invalid_payment_record');
                    }

                    $id = (string) $payment['id'];

                    // Defensive de-duplication across provider pages. The API
                    // id is the transaction identity; local Freemius mirrors
                    // are excluded separately by the platform statistics query.
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;

                    $currency = strtoupper((string) $payment['currency']);
                    $type = strtolower((string) $payment['type']);
                    $amount = abs((float) $payment['gross']);
                    $gatewayFee = abs((float) ($payment['gateway_fee'] ?? 0));
                    $tax = abs((float) ($payment['vat'] ?? 0));
                    $totals[$currency] ??= [
                        'gross_collected' => 0.0,
                        'refunds' => 0.0,
                        'chargebacks' => 0.0,
                        'disputed' => 0.0,
                        'won_disputes' => 0.0,
                        'gateway_fees' => 0.0,
                        'taxes' => 0.0,
                    ];

                    match ($type) {
                        'payment' => $this->addPayment($totals[$currency], $amount, $gatewayFee, $tax),
                        'refund' => $this->addAmount($totals[$currency], 'refunds', $amount),
                        'chargeback', 'lost_dispute' => $this->addAmount($totals[$currency], 'chargebacks', $amount),
                        'disputed' => $this->addAmount($totals[$currency], 'disputed', $amount),
                        'won_dispute' => $this->addAmount($totals[$currency], 'won_disputes', $amount),
                    };
                }

                if (count($payments) < self::PAGE_SIZE) {
                    $complete = true;
                    break;
                }

                $offset += self::PAGE_SIZE;
            }
        } catch (\Throwable) {
            return $this->unavailable('provider_error');
        }

        if (! $complete) {
            // Do not publish partial lifetime revenue if the bounded scan could
            // not prove that every provider page was read.
            return $this->unavailable('page_limit_reached');
        }

        ksort($totals);

        return [
            'status' => 'available',
            'complete' => true,
            'payment_rows' => count($seen),
            'gross_collected_by_currency' => $this->pluckTotals($totals, 'gross_collected'),
            'refunds_by_currency' => $this->pluckTotals($totals, 'refunds'),
            'chargebacks_by_currency' => $this->pluckTotals($totals, 'chargebacks'),
            'disputed_by_currency' => $this->pluckTotals($totals, 'disputed'),
            'won_disputes_by_currency' => $this->pluckTotals($totals, 'won_disputes'),
            'gateway_fees_by_currency' => $this->pluckTotals($totals, 'gateway_fees'),
            'taxes_by_currency' => $this->pluckTotals($totals, 'taxes'),
        ];
    }

    /**
     * Validate provider data before it participates in financial calculations.
     *
     * @param  array<string, mixed>  $payment
     */
    private function isValidPayment(array $payment): bool
    {
        $types = ['payment', 'refund', 'chargeback', 'disputed', 'won_dispute', 'lost_dispute'];

        if (! isset($payment['id']) || ! is_scalar($payment['id'])
            || ! isset($payment['type']) || ! is_string($payment['type'])
            || ! in_array(strtolower($payment['type']), $types, true)
            || ! isset($payment['currency']) || ! is_string($payment['currency'])
            || ! preg_match('/^[a-zA-Z]{3}$/', $payment['currency'])
            || ! isset($payment['gross']) || ! is_numeric($payment['gross'])
            || ! is_finite((float) $payment['gross'])) {
            return false;
        }

        foreach (['gateway_fee', 'vat'] as $optionalAmount) {
            if (isset($payment[$optionalAmount])
                && (! is_numeric($payment[$optionalAmount]) || ! is_finite((float) $payment[$optionalAmount]))) {
                return false;
            }
        }

        return true;
    }

    private function addPayment(array &$totals, float $gross, float $fee, float $tax): void
    {
        $totals['gross_collected'] += $gross;
        $totals['gateway_fees'] += $fee;
        $totals['taxes'] += $tax;
    }

    private function addAmount(array &$totals, string $key, float $amount): void
    {
        $totals[$key] += $amount;
    }

    /**
     * @param  array<string, array<string, float>>  $totals
     * @return array<string, float>
     */
    private function pluckTotals(array $totals, string $key): array
    {
        $result = [];

        foreach ($totals as $currency => $amounts) {
            $rounded = round($amounts[$key], 2);

            if ($rounded !== 0.0) {
                $result[$currency] = $rounded;
            }
        }

        return $result;
    }

    private function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'complete' => false,
            'reason' => $reason,
            'payment_rows' => null,
            'gross_collected_by_currency' => null,
            'refunds_by_currency' => null,
            'chargebacks_by_currency' => null,
            'disputed_by_currency' => null,
            'won_disputes_by_currency' => null,
            'gateway_fees_by_currency' => null,
            'taxes_by_currency' => null,
        ];
    }
}
