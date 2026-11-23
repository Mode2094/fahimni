'use client';

import { useCallback, useEffect, useState } from 'react';
import {
  BookOpen, CalendarDays, Check, ChevronLeft, ChevronRight, ClipboardCheck,
  ClipboardList, Eye, EyeOff, FileVideo, Layers, Radio, Sparkles, Ticket,
  UserCheck, type LucideIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { useLocale } from '@/store/locale';
import { SectionCard, useIsDesktop } from '@/components/dashboard/dashboard-kit';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { API_BASE, authFetch } from '@/lib/auth-client';

/**
 * دليل الإعداد — the teacher onboarding guide.
 *
 * Renders the checklist derived from the tenant's real data (the API never
 * hardcodes a "done"), with a short Arabic help line + a concrete example per
 * step, live progress, and a hide/restore control whose state lives on the user
 * record so it survives every session and device.
 *
 * It fetches its own data so any new-teacher surface can mount it as-is.
 */

type Step = { key: string; done: boolean; href: string; optional: boolean };

type GuideData = {
  steps: Step[];
  completed: number;
  total: number;
  required_completed?: number;
  required_total?: number;
  all_done: boolean;
  dismissed: boolean;
};

const STEP_META: Record<string, {
  ar: string; en: string; icon: LucideIcon;
  help: { ar: string; en: string }; example: { ar: string; en: string };
}> = {
  profile: {
    ar: 'أكمل ملفك الشخصي', en: 'Complete your profile', icon: UserCheck,
    help: { ar: 'أضف اسمك ورقم هاتفك حتى يستطيع طلابك التواصل معك بثقة.', en: 'Add your name and phone so students can reach you with confidence.' },
    example: { ar: 'مثال: الاسم «أ. محمد» ورقم واتساب فعّال.', en: 'Example: name “Mr. Mohammed” and a working WhatsApp number.' },
  },
  course: {
    ar: 'أنشئ أول كورس', en: 'Create your first course', icon: BookOpen,
    help: { ar: 'الكورس هو الحاوية التي تجمع دروسك واختباراتك.', en: 'A course is the container that holds your lessons and exams.' },
    example: { ar: 'مثال: «أساسيات الرياضيات — الفصل الأول».', en: 'Example: “Math Fundamentals — Term 1”.' },
  },
  lesson: {
    ar: 'أضف درساً داخل الكورس', en: 'Add a lesson to the course', icon: Layers,
    help: { ar: 'قسّم الكورس إلى أقسام ودروس مرتّبة ليسهل متابعتها.', en: 'Split the course into ordered sections and lessons.' },
    example: { ar: 'مثال: قسم «الفصل الأول» ← درس «المتطابقات».', en: 'Example: section “Term 1” → lesson “Identities”.' },
  },
  content: {
    ar: 'ارفع فيديو أو محتوى تعليمي', en: 'Upload a teaching video or content', icon: FileVideo,
    help: { ar: 'أضف فيديو، أو ملف PDF، أو نصاً شرحياً لأي درس.', en: 'Add a video, a PDF file, or a text explanation to any lesson.' },
    example: { ar: 'مثال: فيديو مدته 10 دقائق أو ملخص PDF.', en: 'Example: a 10-minute video or a PDF summary.' },
  },
  exam: {
    ar: 'أنشئ اختباراً', en: 'Create an exam', icon: ClipboardCheck,
    help: { ar: 'اختبار اختياري لقياس فهم طلابك بعد الدرس.', en: 'An optional exam to measure students’ understanding.' },
    example: { ar: 'مثال: 5 أسئلة اختيار من متعدد.', en: 'Example: 5 multiple-choice questions.' },
  },
  enrollment: {
    ar: 'جهّز طريقة تسجيل الطلاب', en: 'Set up student enrolment', icon: Ticket,
    help: { ar: 'أنشئ بطاقة تسجيل برمز QR أو شارك رابط منصتك ليسجّل الطلاب.', en: 'Generate a QR registration card or share your platform link.' },
    example: { ar: 'مثال: بطاقة تسجيل واحدة لكل مجموعة طلاب.', en: 'Example: one registration card per student group.' },
  },
  assignment: {
    ar: 'أضف واجباً لطلابك', en: 'Add an assignment', icon: ClipboardList,
    help: { ar: 'واجب مع تسليم الطلاب لي طباعة عملية.', en: 'An assignment with student submissions for real practice.' },
    example: { ar: 'مثال: حل 10 مسائل قبل الدرس القادم.', en: 'Example: solve 10 problems before the next lesson.' },
  },
  live: {
    ar: 'ابدأ بثاً مباشراً', en: 'Hold a live session', icon: Radio,
    help: { ar: 'احجز جلسة بث مباشر تفاعلية مع طلابك.', en: 'Host an interactive live session with your students.' },
    example: { ar: 'مثال: مراجعة مباشرة قبل الاختبار.', en: 'Example: a live review before the exam.' },
  },
  schedule: {
    ar: 'جدوِل درساً بموعد ثابت', en: 'Schedule a lesson', icon: CalendarDays,
    help: { ar: 'حدّد موعداً أسبوعياً ثابتاً ليتذكّره طلابك وتصله إشعارات.', en: 'Set a fixed weekly time so students are reminded automatically.' },
    example: { ar: 'مثال: كل يوم أحد الساعة 6 مساءً.', en: 'Example: every Sunday at 6 PM.' },
  },
};

function tenantHeader(): Record<string, string> {
  const tid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;

  return tid ? { 'X-Tenant-Id': tid } : {};
}

export default function SetupGuide() {
  const { locale } = useLocale();
  const isDesktop = useIsDesktop();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const Arrow = locale === 'ar' ? ChevronLeft : ChevronRight;

  const [data, setData] = useState<GuideData | null>(null);
  const [dismissed, setDismissed] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await authFetch(`${API_BASE}/teacher/onboarding`, { headers: tenantHeader() });
      if (!res.ok) return;
      const d = (await res.json()) as GuideData;
      setData(d);
      setDismissed(!!d.dismissed);
    } catch {
      // The guide is an enhancement — a failed fetch must never break the page.
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const setHidden = async (hide: boolean) => {
    setBusy(true);
    try {
      const res = await authFetch(`${API_BASE}/teacher/onboarding/${hide ? 'dismiss' : 'restore'}`, {
        method: 'POST',
        headers: tenantHeader(),
      });
      if (res.ok) {
        setDismissed(hide);
        toast.success(hide
          ? t('تم إخفاء الدليل — يمكنك استعادته في أي وقت', 'Guide hidden — you can restore it anytime')
          : t('تم استعادة الدليل', 'Guide restored'));
      }
    } catch {
      toast.error(t('تعذّر تحديث الدليل', 'Could not update the guide'));
    } finally {
      setBusy(false);
    }
  };

  if (!data) return null;

  const requiredTotal = data.required_total ?? data.total;
  const requiredCompleted = data.required_completed ?? data.completed;

  // Hidden: offer a clear way back, but never nag a fully-set-up teacher.
  if (dismissed) {
    if (data.all_done) return null;

    return (
      <div
        data-testid="setup-guide-restore"
        className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-teal-100 bg-teal-50/40 px-4 py-3"
      >
        <p className="text-sm font-medium text-teal-800">
          {t('هل تريد متابعة تجهيز منصتك؟', 'Continue setting up your platform?')}{' '}
          <span className="font-bold tabular-nums">({requiredCompleted}/{requiredTotal})</span>
        </p>
        <Button
          size="sm"
          variant="outline"
          onClick={() => setHidden(false)}
          disabled={busy}
          className="gap-1.5 rounded-full border-teal-200 text-teal-700 hover:bg-teal-50"
        >
          <Eye className="h-3.5 w-3.5" />{t('استعادة الدليل', 'Restore guide')}
        </Button>
      </div>
    );
  }

  if (data.all_done || data.steps.length === 0) return null;

  const pct = requiredTotal ? Math.round((requiredCompleted / requiredTotal) * 100) : 0;

  return (
    <SectionCard
      icon={Sparkles}
      title={t('دليل الإعداد', 'Setup guide')}
      description={t(
        'أكمل هذه الخطوات لتجهيز منصتك لطلابك — كل خطوة تُعلَّم تلقائياً عند إنجازها.',
        'Complete these steps to get your platform ready — each one is checked automatically.',
      )}
      badge={
        <span
          data-testid="setup-guide-progress"
          className="shrink-0 rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-semibold tabular-nums text-teal-700"
        >
          {requiredCompleted} / {requiredTotal}
        </span>
      }
      defaultOpen
      forceOpen={isDesktop}
      testId="setup-guide"
    >
      <div className="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
        <div className="h-full rounded-full bg-teal-500 transition-all" style={{ width: `${pct}%` }} />
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        {data.steps.map((s) => {
          const meta = STEP_META[s.key];
          if (!meta) return null;
          const Icon = meta.icon;

          return (
            <a
              key={s.key}
              href={s.href}
              data-testid={`setup-step-${s.key}`}
              data-done={s.done}
              aria-label={s.done ? `${t(meta.ar, meta.en)} — ${t('تم', 'Done')}` : t(meta.ar, meta.en)}
              className={cn(
                'flex items-start gap-3 rounded-xl border p-3 transition',
                s.done ? 'border-slate-100 bg-slate-50/50' : 'border-teal-100 bg-white hover:bg-teal-50/40',
              )}
            >
              <span className={cn('mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', s.done ? 'bg-emerald-50' : 'bg-teal-500/10')}>
                {s.done ? <Check className="h-4 w-4 text-emerald-600" /> : <Icon className="h-4 w-4 text-teal-600" />}
              </span>

              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className={cn('text-sm font-semibold', s.done ? 'text-gray-400 line-through' : 'text-gray-800')}>
                    {t(meta.ar, meta.en)}
                  </span>
                  {s.optional && (
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">
                      {t('اختياري', 'Optional')}
                    </span>
                  )}
                </span>
                <span className="mt-0.5 block text-xs text-muted-foreground">{t(meta.help.ar, meta.help.en)}</span>
                <span className="mt-0.5 block text-[11px] text-teal-700/80">{t(meta.example.ar, meta.example.en)}</span>
              </span>

              {!s.done && <Arrow className="mt-1 h-4 w-4 shrink-0 text-teal-500" />}
            </a>
          );
        })}
      </div>

      <div className="mt-4 flex justify-end">
        <button
          type="button"
          onClick={() => setHidden(true)}
          disabled={busy}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 transition hover:text-gray-700 disabled:opacity-60"
        >
          <EyeOff className="h-3.5 w-3.5" />{t('إخفاء الدليل', 'Hide guide')}
        </button>
      </div>
    </SectionCard>
  );
}
