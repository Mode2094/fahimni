'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Users, GraduationCap, BookOpen, CreditCard, UserPlus, Wallet, Loader2, TrendingUp, Sparkles, Crown, FileText, Ticket } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminDashboardPage() {
  const { check, user } = useAuth();
  const { locale } = useLocale();
  const { formatPrice, load: loadSettings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!check()) return;
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    loadSettings();
    fetch(`${API}/dashboard`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then(d => setStats(d)).catch(() => {})
      .finally(() => setLoading(false));
  }, [check()]);

  const cards = [
    { label: t('المعلمون', 'Teachers'), value: stats?.total_tenants ?? 0, icon: Users, color: '#0d9488', href: '/admin/teachers' },
    { label: t('الطلاب', 'Students'), value: stats?.total_students ?? 0, icon: GraduationCap, color: '#7c3aed', href: '/admin/students' },
    { label: t('الكورسات', 'Courses'), value: stats?.total_courses ?? 0, icon: BookOpen, color: '#059669', href: '/admin/teachers' },
    { label: t('الاشتراكات النشطة', 'Active subscriptions'), value: stats?.active_subscriptions ?? 0, icon: CreditCard, color: '#0891b2', href: '/admin/subscriptions' },
    { label: t('مستخدمي المنصة', 'Platform users'), value: stats?.total_users ?? 0, icon: UserPlus, color: '#ea580c', href: '/admin/teachers' },
    { label: t('إجمالي الإيرادات', 'Total revenue'), value: formatPrice(stats?.total_revenue ?? 0), icon: Wallet, color: '#dc2626', href: '/admin/payments', big: true },
  ];

  const quickLinks = [
    { label: t('الخطط والأسعار', 'Plans & pricing'), icon: Crown, href: '/admin/plans', color: '#d97706' },
    { label: t('تذاكر الدعم', 'Support tickets'), icon: Ticket, href: '/admin/tickets', color: '#7c3aed' },
    { label: t('الفواتير', 'Invoices'), icon: FileText, href: '/admin/invoices', color: '#059669' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      {/* ─── ترحيب ─────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-6 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #115e54 55%, #0d9488 100%)' }}>
        <div className="absolute top-0 left-1/4 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-64 h-64 bg-emerald-300/15 rounded-full blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />
        <div className="relative z-10 flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full glass px-3 py-1 text-xs font-medium text-white mb-3">
              <Sparkles className="h-3.5 w-3.5 text-teal-200" />
              {t('مركز إدارة المنصة', 'Platform control center')}
            </span>
            <h1 className="text-2xl sm:text-3xl font-bold text-white leading-snug">{t('لوحة الإدارة', 'Admin Dashboard')}</h1>
            <p className="text-white/65 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('إحصائيات المنصة لحظياً — المعلمون، الطلاب، الاشتراكات والإيرادات', 'Live platform stats — teachers, students, subscriptions & revenue')}
            </p>
            <div className="flex flex-wrap gap-2.5 mt-5">
              {quickLinks.map((q, i) => (
                <a key={i} href={q.href} className="inline-flex items-center gap-1.5 rounded-full glass px-4 py-2 text-xs font-bold text-white transition-all hover:bg-white/15 hover:scale-[1.03]">
                  <q.icon className="h-3.5 w-3.5" style={{ color: q.color }} /> {q.label}
                </a>
              ))}
            </div>
          </div>
          <div className="glass rounded-3xl px-6 py-5 min-w-[220px]">
            <p className="text-[11px] text-white/60 mb-1">{t('إجمالي إيرادات المنصة', 'Platform total revenue')}</p>
            <div className="text-3xl font-black text-white">{formatPrice(stats?.total_revenue ?? 0)}</div>
            <div className="mt-3 pt-3 border-t border-white/10 flex items-center gap-1.5 text-xs text-white/60">
              <TrendingUp className="h-3.5 w-3.5 text-emerald-300" />
              {t('جميع الإيرادات المكتملة', 'All completed revenue')}
            </div>
          </div>
        </div>
      </section>

      {loading ? (
        <div className="flex justify-center py-24"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>
      ) : (
        <>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {cards.map((s, i) => (
              <Card key={i} className="group border-0 shadow-sm card-hover overflow-hidden relative">
                <div className="absolute -top-8 -end-8 h-24 w-24 rounded-full opacity-10 transition-opacity group-hover:opacity-20" style={{ background: s.color }} />
                <CardContent className="p-6">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: `${s.color}12` }}>
                      <s.icon className="h-6 w-6" style={{ color: s.color }} />
                    </div>
                    <a href={s.href} className="text-[10px] font-bold text-muted-foreground/50 hover:text-teal-700 transition-colors">{t('عرض', 'View')} →</a>
                  </div>
                  <div className={`font-black tracking-tight ${s.big ? 'text-2xl' : 'text-3xl'}`}>{s.value}</div>
                  <div className="text-sm text-muted-foreground mt-1">{s.label}</div>
                </CardContent>
              </Card>
            ))}
          </div>

          <Card className="border-0 shadow-md overflow-hidden">
            <div className="p-6 sm:p-8 relative overflow-hidden" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54 60%, #0d9488)' }}>
              <div className="absolute -top-16 right-1/4 w-64 h-64 rounded-full bg-teal-300/15 blur-3xl" />
              <div className="relative flex flex-wrap items-center justify-between gap-6">
                <div className="flex items-center gap-4">
                  <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 border border-white/10"><TrendingUp className="h-7 w-7 text-teal-200" /></div>
                  <div>
                    <p className="text-white/60 text-sm">{t('نبذة المنصة', 'Platform snapshot')}</p>
                    <p className="text-2xl sm:text-3xl font-black text-white mt-0.5">{formatPrice(stats?.total_revenue ?? 0)}</p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2.5">
                  {[
                    { label: t('معلمون', 'Teachers'), value: stats?.total_tenants ?? 0 },
                    { label: t('طلاب', 'Students'), value: stats?.total_students ?? 0 },
                    { label: t('كورسات', 'Courses'), value: stats?.total_courses ?? 0 },
                    { label: t('اشتراكات', 'Subscriptions'), value: stats?.active_subscriptions ?? 0 },
                  ].map((s, i) => (
                    <div key={i} className="glass rounded-2xl px-4 py-3 text-center min-w-[90px]">
                      <div className="text-lg font-black text-white">{s.value}</div>
                      <div className="text-[10px] text-white/60 mt-0.5">{s.label}</div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </Card>
        </>
      )}
    </div>
  );
}
