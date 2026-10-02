'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Crown, Check, X, Sparkles, ArrowLeft, RefreshCw, ShieldCheck, UserPlus, HelpCircle } from 'lucide-react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { useAssistant } from '@/store/assistant';
import type { PlanRecommendationResponse } from '@/types/api';

function HighlightRow({ f, isAr }: { f: any; isAr: boolean }) {
  const value = isAr ? f.display_ar : f.display_en;
  const show =
    f.type === 'boolean' ? value === (isAr ? 'مفعل' : 'Enabled') : f.type !== 'select';

  if (!show) return null;

  return (
    <div className="flex items-center justify-between gap-2 text-[13px]">
      <span className="flex items-center gap-1.5 text-slate-600">
        <Check className="h-3.5 w-3.5 text-emerald-500 shrink-0" />
        {isAr ? f.label_ar : f.label_en}
      </span>
      <span className="font-semibold text-slate-800 whitespace-nowrap">{value}</span>
    </div>
  );
}

export default function PlanResultCard({ result }: { result: PlanRecommendationResponse }) {
  const { locale } = useLocale();
  const { formatPrice } = useSettings();
  const router = useRouter();
  const isAr = locale === 'ar';
  const [loggedIn] = useState(() => typeof window !== 'undefined' && !!localStorage.getItem('lms_authed'));
  const plan = result.recommended;

  const checkout = (id: string) => router.push(loggedIn ? `/checkout?plan=${id}&cycle=monthly` : '/register/teacher');

  const pct = plan?.max_score ? Math.min(100, Math.round((plan.score / plan.max_score) * 100)) : 0;

  return (
    <div className="w-full max-w-[90%] overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-slate-100 animate-in">
      {/* ─── الترويسة ─────────────────────────────── */}
      <div className="relative bg-gradient-to-br from-teal-600 via-teal-500 to-emerald-500 p-4 text-white">
        <div className="absolute -end-6 -top-8 h-24 w-24 rounded-full bg-white/10 blur-2xl" />
        <div className="flex items-center gap-1.5 text-[11px] font-medium text-white/80 mb-1">
          <Sparkles className="h-3.5 w-3.5" />
          {isAr ? 'الخطة الأنسب لك' : 'Best plan for you'}
        </div>
        <div className="flex items-center justify-between gap-2">
          <h4 className="text-xl font-extrabold">{plan?.name}</h4>
          {plan?.is_current && (
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-400/90 px-2.5 py-1 text-[10px] font-bold text-emerald-950">
              <ShieldCheck className="h-3 w-3" />{isAr ? 'خطتك الحالية' : 'Your plan'}
            </span>
          )}
        </div>
        <div className="mt-1 flex items-end gap-1.5">
          <span className="text-2xl font-extrabold">{formatPrice(plan?.monthly_price)}</span>
          <span className="pb-0.5 text-xs text-white/80">{isAr ? '/ شهرياً' : '/ month'}</span>
        </div>
        {!!plan?.trial_days && (
          <div className="mt-2 inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-semibold backdrop-blur">
            <Crown className="h-3 w-3 text-amber-300" />
            {isAr ? `${plan.trial_days} أيام تجربة` : `${plan.trial_days}-day trial`}
          </div>
        )}
      </div>

      <div className="space-y-4 p-4">
        {/* ─── نسبة التوافق ────────────────────────── */}
        <div>
          <div className="mb-1 flex items-center justify-between text-[11px] text-slate-500">
            <span>{isAr ? 'نسبة التوافق مع احتياجاتك' : 'Match with your needs'}</span>
            <span className="font-bold text-violet-600">{pct}%</span>
          </div>
          <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-gradient-to-r from-teal-500 to-teal-600 transition-all duration-700"
              style={{ width: `${pct}%` }}
            />
          </div>
        </div>

        {/* ─── لماذا هذه الخطة؟ ────────────────────── */}
        {(plan?.reasons?.length ?? 0) > 0 && (
          <div>
            <p className="mb-2 text-[11px] font-bold text-slate-400 uppercase tracking-wide">
              {isAr ? 'لماذا هذه الخطة لك؟' : 'Why this plan?'}
            </p>
            <ul className="space-y-1.5">
              {plan!.reasons.map((r, i) => (
                <li key={i} className="flex items-start gap-2 text-[13px] text-slate-700">
                  <Sparkles className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-500" />
                  {isAr ? r.ar : r.en}
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* ─── كل الميزات (مفعّلة ✓ / معطلة ✗) ──────── */}
        {(plan?.features?.length ?? 0) > 0 && (
          <div>
            <p className="mb-2 text-[11px] font-bold text-slate-400 uppercase tracking-wide">
              {isAr ? 'ميزات هذه الخطة' : 'This plan includes'}
            </p>
            <div className="max-h-56 space-y-1 overflow-y-auto pr-1">
              {plan!.features.filter((f: any) => f.value === '1' || f.value === 'true' || f.value === 'on').map((f: any) => {
                const isBool = f.type === 'boolean';
                const display = isAr ? f.display_ar : f.display_en;
                return (
                  <div key={f.key} className="group relative flex items-center gap-2 rounded-lg px-2 py-1 text-[12px]">
                    <span className="h-3 w-1 rounded-full bg-emerald-500 shrink-0" />
                    <span className="min-w-0 text-slate-700">
                      {isAr ? f.label_ar : f.label_en}
                      {!isBool && display !== 'مفعل' && display !== 'Enabled' && <b className="text-slate-500"> · {display}</b>}
                    </span>
                    {(f.desc_ar || f.desc_en) && (
                      <span className="group relative inline-flex ms-auto shrink-0">
                        <HelpCircle className="h-3 w-3 text-gray-300 hover:text-teal-600 cursor-help transition-colors" />
                        <span className="pointer-events-none absolute bottom-full end-0 z-30 mb-1.5 hidden w-56 rounded-xl bg-gray-900 text-white text-[10px] leading-relaxed p-3 shadow-xl group-hover:block">
                          {isAr ? f.desc_ar : f.desc_en}
                        </span>
                      </span>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* ─── الأزرار ─────────────────────────────── */}
        {plan?.is_current ? (
          <button
            onClick={() => router.push('/pricing')}
            className="w-full rounded-full bg-slate-100 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-200"
          >
            {isAr ? 'تصفح كل الخطط للمقارنة' : 'Browse all plans'}
          </button>
        ) : (
          <>
            <button
              onClick={() => checkout(plan!.id)}
              className="flex w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-teal-600 to-teal-500 py-3 text-sm font-bold text-white shadow-lg shadow-teal-500/30 transition hover:opacity-90 active:scale-[0.98]"
            >
              {loggedIn ? (
                isAr ? `اشترك الآن · ${formatPrice(plan?.monthly_price)}/شهر` : `Subscribe now · ${formatPrice(plan?.monthly_price)}/mo`
              ) : (
                <>
                  <UserPlus className="h-4 w-4" />
                  {isAr ? 'أنشئ حسابك واشترك' : 'Create account & subscribe'}
                </>
              )}
            </button>
            <button
              onClick={() => router.push('/pricing')}
              className="w-full rounded-full border border-slate-200 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-50"
            >
              {isAr ? 'مقارنة كل الخطط' : 'Compare all plans'}
            </button>
          </>
        )}

        {/* ─── بدائل ───────────────────────────────── */}
        {(result.alternatives?.length ?? 0) > 0 && (
          <div className="border-t border-slate-100 pt-3">
            <p className="mb-2 text-[11px] font-bold text-slate-400 uppercase tracking-wide">
              {isAr ? 'بدائل مناسبة أيضاً' : 'Suitable alternatives'}
            </p>
            <div className="space-y-1.5">
              {result.alternatives.slice(0, 2).map((alt) => (
                <button
                  key={alt.id}
                  onClick={() => checkout(alt.id)}
                  className="flex w-full items-center justify-between rounded-xl border border-slate-100 bg-white px-3 py-2 text-[13px] transition hover:border-violet-200 hover:bg-violet-50/50"
                >
                  <span className="font-semibold text-slate-700">{alt.name}</span>
                  <span className="flex items-center gap-1 text-slate-500">
                    {formatPrice(alt.monthly_price)}{isAr ? '/شهر' : '/mo'}
                    <ArrowLeft className="h-3 w-3" />
                  </span>
                </button>
              ))}
            </div>
          </div>
        )}

        <button
          onClick={() => useAssistant.getState().restart()}
          className="mx-auto flex items-center gap-1.5 text-[11px] font-semibold text-slate-400 transition hover:text-violet-600"
        >
          <RefreshCw className="h-3 w-3" />
          {isAr ? 'إعادة الاختيار من جديد' : 'Choose again'}
        </button>
      </div>
    </div>
  );
}
