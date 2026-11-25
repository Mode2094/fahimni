'use client';

import { useLocale } from '@/store/locale';

type CurrencyAmounts = Record<string, number> | null | undefined;

export interface AdminSubscriptionMetricsData {
  total_teachers: number;
  active_accounts: number;
  valid_trials: number;
  paid_subscribers: number;
  admin_access: number;
  expired_access: number;
  effective_access: number;
  unverified_access: number;
  pending_subscriptions: number;
  historical_records: number;
  financials?: {
    provider_status?: 'available' | 'unavailable';
    collected_complete?: boolean;
    gross_collected_by_currency?: CurrencyAmounts;
    refunds_by_currency?: CurrencyAmounts;
    net_collected_by_currency?: CurrencyAmounts;
    mrr_by_currency?: CurrencyAmounts;
    pending_payment_transactions?: number;
    pending_payment_amount_by_currency?: CurrencyAmounts;
    pending_checkout_intents?: number;
  };
}

function amountLabel(amounts: CurrencyAmounts, fallbackCurrency = 'USD'): string {
  if (!amounts) return '—';

  const entries = Object.entries(amounts).sort(([currencyA], [currencyB]) => currencyA.localeCompare(currencyB));

  if (entries.length === 0) return `0.00 ${fallbackCurrency}`;

  return entries
    .map(([currency, amount]) => `${Number(amount).toFixed(2)} ${currency}`)
    .join(' · ');
}

function MetricLink({
  href,
  label,
  value,
  testId,
}: {
  href: string;
  label: string;
  value: number;
  testId: string;
}) {
  return (
    <a
      href={href}
      data-testid={testId}
      className="flex min-h-[76px] min-w-0 flex-col justify-between rounded-xl border border-border bg-card p-3 transition-colors hover:border-primary/40 hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:p-4"
    >
      <span className="text-xs font-medium leading-snug text-muted-foreground">{label}</span>
      <span className="mt-1 text-xl font-bold tabular-nums sm:text-2xl">{value}</span>
    </a>
  );
}

function DetailMetric({
  label,
  value,
  href,
  testId,
}: {
  label: string;
  value: number;
  href: string;
  testId: string;
}) {
  return (
    <a
      href={href}
      data-testid={testId}
      className="flex min-h-14 items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2.5 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span className="text-xs font-medium leading-snug text-muted-foreground sm:text-sm">{label}</span>
      <span className="shrink-0 text-lg font-bold tabular-nums">{value}</span>
    </a>
  );
}

function FinancialMetric({
  label,
  value,
  description,
  testId,
}: {
  label: string;
  value: string;
  description?: string;
  testId: string;
}) {
  return (
    <div className="min-w-0 rounded-lg border border-border bg-background px-3 py-2.5">
      <dt className="text-xs font-medium leading-snug text-muted-foreground">{label}</dt>
      <dd data-testid={testId} className="mt-1 break-words text-sm font-bold tabular-nums sm:text-base">
        {value}
      </dd>
      {description && <p className="mt-1 text-[11px] leading-snug text-muted-foreground">{description}</p>}
    </div>
  );
}

export default function AdminSubscriptionMetrics({
  metrics,
}: {
  metrics: AdminSubscriptionMetricsData | null | undefined;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  if (!metrics) return null;

  const financials = metrics.financials ?? {};
  const collectedIsKnown = financials.collected_complete === true;
  const financialStatus = financials.provider_status ?? 'unavailable';

  const primaryMetrics = [
    {
      key: 'total_teachers',
      label: t('إجمالي المعلمين', 'Teachers'),
      value: metrics.total_teachers,
      href: '/admin/teachers',
    },
    {
      key: 'active_accounts',
      label: t('حسابات مفعّلة', 'Enabled accounts'),
      value: metrics.active_accounts,
      href: '/admin/teachers?is_active=1',
    },
    {
      key: 'valid_trials',
      label: t('تجربة مجانية', 'Free trial'),
      value: metrics.valid_trials,
      href: '/admin/teachers?subscription_state=valid_trial',
    },
    {
      key: 'paid_subscribers',
      label: t('مشترك مدفوع', 'Paid subscribers'),
      value: metrics.paid_subscribers,
      href: '/admin/teachers?subscription_state=paid',
    },
  ];

  const detailMetrics = [
    {
      key: 'admin_access',
      label: t('وصول إداري', 'Administrative access'),
      value: metrics.admin_access,
      href: '/admin/teachers?subscription_state=admin',
    },
    {
      key: 'expired_access',
      label: t('منتهي', 'Expired'),
      value: metrics.expired_access,
      href: '/admin/teachers?subscription_state=expired',
    },
    {
      key: 'unverified_access',
      label: t('وصول غير مثبت الدفع', 'Access without verified payment'),
      value: metrics.unverified_access,
      href: '/admin/teachers?subscription_state=unverified',
    },
    {
      key: 'effective_access',
      label: t('وصول فعلي الآن', 'Effective access now'),
      value: metrics.effective_access,
      href: '/admin/teachers?subscription_state=effective',
    },
    {
      key: 'pending_subscriptions',
      label: t('قيد الدفع', 'Pending checkout'),
      value: metrics.pending_subscriptions,
      href: '/admin/subscriptions?status=pending',
    },
    {
      key: 'historical_records',
      label: t('سجلات الاشتراكات التاريخية', 'Historical subscription records'),
      value: metrics.historical_records,
      href: '/admin/subscriptions',
    },
  ];

  const grossLabel = collectedIsKnown
    ? amountLabel(financials.gross_collected_by_currency)
    : '—';
  const refundLabel = collectedIsKnown
    ? amountLabel(financials.refunds_by_currency)
    : '—';
  const netLabel = collectedIsKnown
    ? amountLabel(financials.net_collected_by_currency)
    : '—';

  return (
    <section className="space-y-3" data-testid="subscription-metrics">
      <div className="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-4" data-testid="stats-grid">
        {primaryMetrics.map((metric) => (
          <MetricLink
            key={metric.key}
            href={metric.href}
            label={metric.label}
            value={metric.value}
            testId={`metric-${metric.key}`}
          />
        ))}
      </div>

      <details className="group rounded-xl border border-border bg-card">
        <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:px-4">
          <span>{t('تفاصيل الاشتراكات والمالية', 'Subscription and finance details')}</span>
          <span aria-hidden="true" className="text-muted-foreground transition-transform group-open:rotate-180">⌄</span>
        </summary>

        <div className="space-y-4 border-t border-border p-3 sm:p-4">
          <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
            {detailMetrics.map((metric) => (
              <DetailMetric
                key={metric.key}
                href={metric.href}
                label={metric.label}
                value={metric.value}
                testId={`metric-${metric.key}`}
              />
            ))}
          </div>

          <div>
            <h2 className="mb-2 text-xs font-semibold text-muted-foreground">
              {t('التحصيل والفوترة', 'Collections and billing')}
            </h2>
            <dl className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
              <FinancialMetric
                label={t('إجمالي التحصيل المؤكد', 'Confirmed gross collected')}
                value={grossLabel}
                description={t('قبل الاستردادات والرسوم والضرائب.', 'Before refunds, fees and taxes.')}
                testId="collected-value"
              />
              <FinancialMetric label={t('المبالغ المستردة', 'Refunds')} value={refundLabel} testId="refunds-value" />
              <FinancialMetric
                label={t('صافي التحصيل بعد الاسترداد/الاعتراض', 'Net after refunds/chargebacks')}
                value={netLabel}
                testId="net-collected-value"
              />
              <FinancialMetric
                label={t('الإيراد الشهري المتكرر (MRR)', 'Monthly recurring revenue (MRR)')}
                value={amountLabel(financials.mrr_by_currency)}
                description={t('العقد السنوي محسوب على 12 شهرًا؛ لا تُخلط العملات.', 'Annual plans are divided by 12; currencies are not mixed.')}
                testId="mrr-value"
              />
              <FinancialMetric
                label={t('معاملات دفع قيد المعالجة', 'Pending payment transactions')}
                value={String(financials.pending_payment_transactions ?? 0)}
                description={amountLabel(financials.pending_payment_amount_by_currency)}
                testId="pending-payment-value"
              />
              <FinancialMetric
                label={t('محاولات Checkout المعلّقة', 'Pending checkout attempts')}
                value={String(financials.pending_checkout_intents ?? metrics.pending_subscriptions)}
                description={t('ليست معاملات محصلة ولا تدخل في الإيراد أو MRR.', 'Not captured transactions; excluded from revenue and MRR.')}
                testId="pending-checkout-value"
              />
            </dl>
          </div>

          {financialStatus !== 'available' && (
            <p role="status" className="text-xs text-amber-700">
              {t(
                'تعذّر التحقق من سجل Freemius؛ تُعرض الإيرادات كغير معروفة لا كصفر.',
                'Freemius totals are unavailable; revenue is unknown, not zero.',
              )}
            </p>
          )}
        </div>
      </details>
    </section>
  );
}
