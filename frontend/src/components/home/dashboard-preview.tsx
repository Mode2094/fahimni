'use client';

import { useLocale } from '@/store/locale';
import {
  LayoutDashboard, BookOpen, Users, Ticket, Radio, BarChart3, Settings,
  Lock, Bell, Search, Plus, TrendingUp,
} from 'lucide-react';

/**
 * A tidy, static preview of the teacher dashboard for the landing hero and
 * «نظرة على المنصة». It is an illustrative mockup — the numbers are sample
 * values, and the frame carries a «معاينة توضيحية» label so it is never
 * mistaken for live data or an activated account.
 *
 * Sidebar labels mirror the real TEACHER_NAV (nav-config.ts) so the preview
 * stays faithful as the product evolves.
 */
export default function DashboardPreview({ withPhone = true }: { withPhone?: boolean }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const nav = [
    { icon: LayoutDashboard, label: t('لوحة التحكم', 'Dashboard'), active: true },
    { icon: BookOpen, label: t('الكورسات', 'Courses') },
    { icon: Users, label: t('الطلاب', 'Students') },
    { icon: Ticket, label: t('البطاقات', 'Cards') },
    { icon: Radio, label: t('البث المباشر', 'Live') },
    { icon: BarChart3, label: t('التحليلات', 'Analytics') },
    { icon: Settings, label: t('الإعدادات', 'Settings') },
  ];

  const stats = [
    { label: t('الطلاب', 'Students'), value: '128', color: '#0d9488' },
    { label: t('الكورسات', 'Courses'), value: '6', color: '#059669' },
    { label: t('الاختبارات', 'Exams'), value: '14', color: '#d97706' },
    { label: t('نسبة الإكمال', 'Completion'), value: '82%', color: '#7c3aed' },
  ];

  const chart = [
    { d: t('أحد', 'Sun'), v: 38 },
    { d: t('إثن', 'Mon'), v: 52 },
    { d: t('ثلا', 'Tue'), v: 44 },
    { d: t('أرب', 'Wed'), v: 66 },
    { d: t('خمي', 'Thu'), v: 58 },
    { d: t('جمع', 'Fri'), v: 74 },
    { d: t('سبت', 'Sat'), v: 61 },
  ];

  const students = [
    { name: t('أحمد محمد', 'Ahmad'), course: t('أساسيات الجبر', 'Algebra basics'), pct: 86, color: '#0d9488' },
    { name: t('سارة علي', 'Sara'), course: t('الفيزياء — الوحدة ٢', 'Physics — Unit 2'), pct: 64, color: '#d97706' },
    { name: t('يوسف حسن', 'Yousef'), course: t('اللغة العربية', 'Arabic'), pct: 41, color: '#7c3aed' },
  ];

  return (
    <div className="relative">
      {/* ─── إطار المتصفح ─────────────────────────────── */}
      <div className="overflow-hidden rounded-2xl border border-teal-100 bg-white shadow-2xl shadow-teal-900/10">
        {/* شريط علوي */}
        <div className="flex items-center gap-3 border-b border-teal-50 bg-[#f7fcfb] px-4 py-2.5">
          <span className="flex gap-1.5" aria-hidden="true">
            <span className="h-2.5 w-2.5 rounded-full bg-rose-300" />
            <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
            <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
          </span>
          <span className="mx-auto flex max-w-[70%] items-center gap-1.5 truncate rounded-full border border-teal-100 bg-white px-3 py-1 text-[10px] text-teal-700" dir="ltr">
            <Lock className="h-3 w-3 shrink-0 text-teal-500" />
            fahimni.me/teacher/dashboard
          </span>
          <span className="hidden rounded-full bg-teal-50 px-2 py-0.5 text-[9px] font-bold text-teal-600 sm:inline">
            {t('معاينة', 'Preview')}
          </span>
        </div>

        <div className="flex">
          {/* الشريط الجانبي */}
          <aside className="hidden w-40 shrink-0 flex-col gap-0.5 border-s border-teal-50 bg-white p-3 sm:flex">
            <div className="mb-3 flex items-center gap-2 px-1">
              <span className="grid h-7 w-7 place-items-center rounded-lg bg-gradient-to-br from-teal-600 to-emerald-500 text-[10px] font-black text-white">F</span>
              <span className="text-[11px] font-bold text-teal-950">{t('منصتي', 'My platform')}</span>
            </div>
            {nav.map((n) => (
              <span
                key={n.label}
                className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-[10.5px] font-medium ${
                  n.active ? 'bg-teal-50 text-teal-700' : 'text-slate-500'
                }`}
              >
                <n.icon className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">{n.label}</span>
              </span>
            ))}
          </aside>

          {/* المحتوى */}
          <div className="min-w-0 flex-1 bg-[#f7fcfb] p-4">
            {/* ترحيب + إجراءات */}
            <div className="mb-4 flex items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate text-[12.5px] font-bold text-teal-950">{t('مساء الخير، أ. محمد', 'Good evening, Mr. Ahmad')}</p>
                <p className="truncate text-[10px] text-slate-400">{t('إليك ملخص منصتك اليوم', 'Here is your platform summary')}</p>
              </div>
              <span className="hidden items-center gap-1 rounded-full bg-teal-600 px-2.5 py-1 text-[10px] font-bold text-white sm:inline-flex">
                <Plus className="h-3 w-3" />
                {t('إنشاء كورس', 'New course')}
              </span>
            </div>

            {/* الإحصائيات */}
            <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
              {stats.map((s) => (
                <div key={s.label} className="rounded-xl border border-teal-50 bg-white p-2.5">
                  <p className="truncate text-[9.5px] text-slate-400">{s.label}</p>
                  <p className="mt-0.5 text-lg font-black" style={{ color: s.color }}>{s.value}</p>
                </div>
              ))}
            </div>

            {/* مخطط + طلاب */}
            <div className="mt-2.5 grid gap-2.5 lg:grid-cols-5">
              <div className="rounded-xl border border-teal-50 bg-white p-3 lg:col-span-2">
                <div className="mb-3 flex items-center justify-between">
                  <p className="text-[10px] font-bold text-teal-950">{t('آخر ٧ أيام', 'Last 7 days')}</p>
                  <TrendingUp className="h-3.5 w-3.5 text-emerald-500" />
                </div>
                <div className="flex h-16 items-end gap-1.5" aria-hidden="true">
                  {chart.map((b) => (
                    <div key={b.d} className="flex flex-1 flex-col items-center gap-1">
                      <span className="w-full rounded-t bg-gradient-to-t from-teal-500/70 to-emerald-400" style={{ height: `${b.v}%` }} />
                      <span className="text-[8px] text-slate-300">{b.d}</span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="rounded-xl border border-teal-50 bg-white p-3 lg:col-span-3">
                <div className="mb-2.5 flex items-center justify-between">
                  <p className="text-[10px] font-bold text-teal-950">{t('أحدث الطلاب', 'Recent students')}</p>
                  <Search className="h-3.5 w-3.5 text-slate-300" />
                </div>
                <ul className="space-y-2.5">
                  {students.map((s) => (
                    <li key={s.name} className="flex items-center gap-2.5">
                      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style={{ background: s.color }}>{s.name.charAt(0)}</span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[10.5px] font-semibold text-slate-700">{s.name}</p>
                        <p className="truncate text-[9px] text-slate-400">{s.course}</p>
                      </div>
                      <div className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 sm:block">
                        <span className="block h-full rounded-full" style={{ width: `${s.pct}%`, background: s.color }} />
                      </div>
                      <span className="w-8 text-end text-[9.5px] font-bold text-slate-500">{s.pct}%</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ─── معاينة الجوال ─────────────────────────────── */}
      {withPhone && (
        <div className="mt-4 flex justify-center sm:absolute sm:-bottom-10 sm:-start-4 sm:mt-0 lg:-start-10 lg:-bottom-12">
          <div className="w-[172px] overflow-hidden rounded-[1.75rem] border-[5px] border-slate-900 bg-white shadow-2xl shadow-slate-900/25">
            <div className="flex items-center justify-center bg-slate-900 py-1">
              <span className="h-1.5 w-12 rounded-full bg-slate-700" />
            </div>
            <div className="bg-[#f7fcfb] p-3">
              <div className="mb-3 flex items-center justify-between">
                <span className="text-[10px] font-bold text-teal-950">{t('لوحتك', 'Your panel')}</span>
                <span className="relative grid h-6 w-6 place-items-center rounded-full bg-white shadow-sm">
                  <Bell className="h-3 w-3 text-slate-400" />
                  <span className="absolute -end-0.5 -top-0.5 h-2 w-2 rounded-full bg-rose-400" />
                </span>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div className="rounded-xl bg-white p-2 shadow-sm">
                  <p className="text-[8px] text-slate-400">{t('الطلاب', 'Students')}</p>
                  <p className="text-sm font-black text-teal-600">128</p>
                </div>
                <div className="rounded-xl bg-white p-2 shadow-sm">
                  <p className="text-[8px] text-slate-400">{t('الإكمال', 'Completion')}</p>
                  <p className="text-sm font-black text-violet-600">82%</p>
                </div>
              </div>
              <div className="mt-2 rounded-xl bg-white p-2 shadow-sm">
                <p className="mb-1.5 text-[8.5px] text-slate-400">{t('أحدث الطلاب', 'Recent students')}</p>
                <ul className="space-y-1.5">
                  {students.slice(0, 2).map((s) => (
                    <li key={s.name} className="flex items-center gap-1.5">
                      <span className="h-4 w-4 rounded-full" style={{ background: s.color }} />
                      <span className="truncate text-[8.5px] text-slate-600">{s.name}</span>
                    </li>
                  ))}
                </ul>
              </div>
              <div className="mt-3 flex items-center justify-around rounded-2xl bg-gradient-to-l from-teal-600 to-emerald-500 py-2 text-white">
                <LayoutDashboard className="h-4 w-4" />
                <BookOpen className="h-4 w-4 opacity-70" />
                <Users className="h-4 w-4 opacity-70" />
                <BarChart3 className="h-4 w-4 opacity-70" />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
