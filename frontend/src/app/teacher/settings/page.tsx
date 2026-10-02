'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  MonitorSmartphone, Loader2, Link2, Palette, Settings2, Eye, Globe, LayoutTemplate,
  FileLock2, Settings, Share2, QrCode, Copy, Check, MessageCircle, Send, Twitter,
  Sparkles, Users, BookOpen, Target, CheckCircle2, Circle, ChevronRight, PartyPopper,
  ShieldCheck, Crown,
} from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { Switch } from '@/components/ui/switch';
import Link from 'next/link';
import QRCode from 'qrcode';
import SiteDesignEditor from '@/components/site/design-editor';
import DomainManager from '@/components/site/domain-manager';
import PageBuilder from '@/components/site/page-builder';
import { dashboardApi } from '@/services/endpoints';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherSettingsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'design' | 'builder' | 'domain' | 'devices' | 'files' | 'video' | 'share'>('design');
  const [policy, setPolicy] = useState<{ enabled: boolean; max_devices: number } | null>(null);
  const [savingPolicy, setSavingPolicy] = useState(false);
  const [filePolicy, setFilePolicy] = useState<{ file_downloads_enabled: boolean } | null>(null);
  const [savingFilePolicy, setSavingFilePolicy] = useState(false);
  const [videoSecurity, setVideoSecurity] = useState<any>(null);
  const [savingVideo, setSavingVideo] = useState(false);
  const [mySlug, setMySlug] = useState('');
  const [site, setSite] = useState<any>(null);
  const [stats, setStats] = useState<any>(null);
  const [copied, setCopied] = useState(false);
  const [qr, setQr] = useState<string | null>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  useEffect(() => {
    if (!check()) return;
    fetch(`${API}/teacher/device-policy`, { headers: headers() })
      .then(r => r.json()).then(dd => setPolicy(dd.policy || null)).catch(() => {});
    fetch(`${API}/teacher/file-policy`, { headers: headers() })
      .then(r => r.json()).then(dd => setFilePolicy(dd.policy || null)).catch(() => {});
    fetch(`${API}/teacher/video-security`, { headers: headers() })
      .then(r => r.json()).then(dd => setVideoSecurity(dd || null)).catch(() => {});
    fetch(`${API}/teacher/site-design`, { headers: headers() })
      .then(r => r.json()).then(d => setSite(d.site || null)).catch(() => {});
    fetch(`${API}/tenants`, { headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(dd => { const s = dd?.tenants?.[0]?.slug; if (s) setMySlug(s); })
      .catch(() => {});
    dashboardApi.teacherStats().then(setStats).catch(() => {});
  }, [check()]);

  const pageUrl = typeof window !== 'undefined' && mySlug ? `${window.location.origin}/t/${mySlug}` : '';

  // توليد QR عند الحاجة (مشاركة صفحتك)
  useEffect(() => {
    if (!pageUrl) return;
    QRCode.toDataURL(pageUrl, { width: 240, margin: 1, errorCorrectionLevel: 'M' })
      .then(setQr)
      .catch(() => setQr(null));
  }, [pageUrl]);

  const copyUrl = async () => {
    if (!pageUrl) return;
    try {
      await navigator.clipboard.writeText(pageUrl);
      setCopied(true);
      toast.success(t('تم نسخ رابط صفحتك', 'Page link copied'));
      setTimeout(() => setCopied(false), 2000);
    } catch { toast.error(t('تعذر النسخ', 'Cannot copy')); }
  };

  const share = (via: 'whatsapp' | 'telegram' | 'x') => {
    if (!pageUrl) return;
    const text = encodeURIComponent(t('تفضل بزيارة صفحتي التعليمية', 'Visit my teaching page'));
    const url = encodeURIComponent(pageUrl);
    if (via === 'whatsapp') window.open(`https://wa.me/?text=${text}%0A${url}`, '_blank');
    if (via === 'telegram') window.open(`https://t.me/share/url?url=${url}&text=${text}`, '_blank');
    if (via === 'x') window.open(`https://twitter.com/intent/tweet?url=${url}&text=${text}`, '_blank');
  };

  const savePolicy = async () => {
    if (!policy) return;
    setSavingPolicy(true);
    try {
      const res = await fetch(`${API}/teacher/device-policy`, { method: 'PUT', headers: headers(), body: JSON.stringify(policy) });
      if (!res.ok) throw new Error();
      toast.success(t('تم حفظ سياسة الأجهزة — تطبق على كل طلابك فوراً', 'Device policy saved — applies to all your students now'));
    } catch { toast.error('Failed'); }
    setSavingPolicy(false);
  };

  const saveFilePolicy = async () => {
    if (!filePolicy) return;
    setSavingFilePolicy(true);
    try {
      const res = await fetch(`${API}/teacher/file-policy`, { method: 'PUT', headers: headers(), body: JSON.stringify(filePolicy) });
      if (!res.ok) throw new Error();
      toast.success(t('تم حفظ إعدادات الملفات — تطبق على كل دروسك فوراً', 'File policy saved — applies to all your lessons now'));
    } catch { toast.error('Failed'); }
    setSavingFilePolicy(false);
  };

  /* ── جاهزية صفحتك ─────────────────────────────── */
  const cfg = site?.site_config;
  const readinessItems = [
    { done: !!site?.name, label: t('اسم المنصة', 'Academy name') },
    { done: !!site?.logo_url, label: t('الشعار', 'Logo') },
    { done: !!site?.primary_color, label: t('ألوان الهوية', 'Brand colors') },
    { done: !!cfg?.template, label: t('القالب', 'Template') },
    { done: (cfg?.hero_slides?.length ?? 0) > 0, label: t('شرائح البطل', 'Hero slides') },
    { done: !!pageUrl, label: t('رابط الصفحة', 'Page link') },
  ];
  const doneCount = readinessItems.filter((i) => i.done).length;
  const readinessPct = Math.round((doneCount / readinessItems.length) * 100);
  const allDone = doneCount === readinessItems.length;

  const tabs = [
    { id: 'design' as const, label: t('تصميم صفحتي', 'Design my page'), hint: t('ألوان، خطوط، شعار وشرائح', 'Colors, fonts, logo & slides'), icon: Palette },
    { id: 'builder' as const, label: t('منشئ الصفحة', 'Page builder'), hint: t('سحب وإفلات حر', 'Free drag & drop'), icon: LayoutTemplate },
    { id: 'domain' as const, label: t('الدومين الخاص', 'Custom domain'), hint: t('اربط دومينك الخاص', 'Connect your own domain'), icon: Globe },
    { id: 'devices' as const, label: t('أجهزة الطلاب', 'Student devices'), hint: t('سياسة تسجيل الدخول', 'Login policy'), icon: MonitorSmartphone },
    { id: 'files' as const, label: t('ملفات الدروس', 'Lesson files'), hint: t('التحكم بالتنزيل', 'Download control'), icon: FileLock2 },
    { id: 'video' as const, label: t('حماية الفيديو', 'Video security'), hint: t('العلامة المائية للطلاب', 'Student watermark'), icon: ShieldCheck },
    { id: 'share' as const, label: t('مشاركة صفحتك', 'Share your page'), hint: t('QR وروابط المشاركة', 'QR & share links'), icon: Share2 },
  ];

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      {/* ─── Hero ─────────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-6 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #115e54 55%, #0d9488 100%)' }}>
        <div className="absolute top-0 left-1/4 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-64 h-64 bg-emerald-300/15 rounded-full blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />

        <div className="relative z-10 flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2 mb-3">
              <span className="inline-flex items-center gap-1.5 rounded-full glass px-3 py-1 text-xs font-medium text-white">
                <Sparkles className="h-3.5 w-3.5 text-teal-200" />
                {t('مركز التحكم بصفحتك', 'Your page control center')}
              </span>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/20 border border-emerald-300/30 px-3 py-1 text-xs font-bold text-emerald-100">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-300 animate-pulse" />
                {site?.name || t('الموقع نشط', 'Site live')}
              </span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-bold text-white leading-snug">{t('إعدادات المعلم', 'Teacher settings')}</h1>
            <p className="text-white/65 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('صمم صفحتك كما تريد، اربط دومينك، وحدد سياسات طلابك — كل شيء من مكان واحد.', 'Design your page, connect your domain and control student policies — all in one place.')}
            </p>
            <div className="flex flex-wrap items-center gap-2.5 mt-5">
              <Button onClick={copyUrl} className="gap-2 rounded-full bg-white text-teal-800 hover:bg-teal-50 shadow-lg" disabled={!pageUrl}>
                {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Link2 className="h-4 w-4" />}
                {copied ? t('تم النسخ', 'Copied!') : t('نسخ رابط صفحتي', 'Copy my page link')}
              </Button>
              <Button onClick={() => window.open(pageUrl, '_blank')} variant="ghost" className="gap-2 rounded-full text-white border border-white/25 glass hover:bg-white/10" disabled={!pageUrl}>
                <Eye className="h-4 w-4" /> {t('معاينة الصفحة', 'Preview page')}
              </Button>
            </div>
          </div>

          {/* إحصائيات سريعة */}
          <div className="grid grid-cols-3 gap-2.5 min-w-[260px]">
            {[
              { icon: Users, label: t('الطلاب', 'Students'), value: stats?.total_students ?? '—' },
              { icon: BookOpen, label: t('الكورسات', 'Courses'), value: stats?.total_courses ?? '—' },
              { icon: Target, label: t('الاختبارات', 'Exams'), value: stats?.total_exams ?? '—' },
            ].map((s, i) => (
              <div key={i} className="glass rounded-2xl px-4 py-3 text-center">
                <s.icon className="mx-auto h-5 w-5 text-teal-200 mb-1.5" />
                <div className="text-xl font-black text-white">{s.value}</div>
                <div className="text-[10px] text-white/60 mt-0.5">{s.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ─── جاهزية صفحتك ─────────────────────────── */}
      <Card className="border-0 shadow-sm overflow-hidden">
        <CardContent className="p-6">
          <div className="flex flex-wrap items-center justify-between gap-4 mb-5">
            <div className="flex items-center gap-2.5">
              <span className={cn('flex h-10 w-10 items-center justify-center rounded-xl', allDone ? 'bg-emerald-500/10' : 'bg-teal-500/10')}>
                {allDone ? <PartyPopper className="h-5 w-5 text-emerald-500" /> : <Sparkles className="h-5 w-5 text-teal-600" />}
              </span>
              <div>
                <h2 className="font-bold text-sm">{allDone ? t('صفحتك جاهزة بالكامل!', 'Your page is fully ready!') : t('جاهزية صفحتك', 'Your page readiness')}</h2>
                <p className="text-[11px] text-muted-foreground">
                  {allDone
                    ? t('كل شيء مضبوط — شارك رابطك وانطلق', 'Everything is set — share your link and go!')
                    : `${doneCount} ${t('من', 'of')} ${readinessItems.length} ${t('خطوات مكتملة', 'steps completed')}`}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-3">
              <div className="text-2xl font-black text-teal-700">{readinessPct}%</div>
              <div className="h-2.5 w-36 sm:w-48 rounded-full bg-slate-100 overflow-hidden">
                <div className={cn('h-full rounded-full transition-all duration-700', allDone ? 'bg-emerald-500' : 'bg-gradient-to-r from-teal-500 to-emerald-500')} style={{ width: `${readinessPct}%` }} />
              </div>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {readinessItems.map((item, i) => (
              <span key={i} className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold border transition-colors',
                item.done ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-400 border-slate-200')}>
                {item.done ? <CheckCircle2 className="h-3.5 w-3.5" /> : <Circle className="h-3.5 w-3.5" />}
                {item.label}
              </span>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* ─── جسم الإعدادات: شريط جانبي + محتوى ─────── */}
      <div className="grid lg:grid-cols-[260px_1fr] gap-6 items-start">
        {/* شريط التبويبات */}
        <aside className="lg:sticky lg:top-20 space-y-1.5">
          <div className="flex lg:flex-col gap-1.5 p-1.5 bg-white rounded-2xl border shadow-sm overflow-x-auto lg:overflow-visible">
            {tabs.map((tb) => (
              <button key={tb.id} onClick={() => setTab(tb.id)} aria-selected={tab === tb.id}
                className={cn('group flex shrink-0 items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all text-start',
                  tab === tb.id
                    ? 'bg-gradient-to-r from-teal-600 to-teal-500 text-white shadow-brand'
                    : 'text-gray-600 hover:bg-teal-50/60')}>
                <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors',
                  tab === tb.id ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-teal-100/60')}>
                  <tb.icon className={cn('h-4.5 w-4.5', tab === tb.id ? 'text-white' : 'text-teal-700')} />
                </span>
                <span className="min-w-0">
                  <span className="block font-bold whitespace-nowrap">{tb.label}</span>
                  <span className={cn('block text-[10px] truncate', tab === tb.id ? 'text-teal-100' : 'text-gray-400')}>{tb.hint}</span>
                </span>
                {tab === tb.id && <ChevronRight className={cn('h-4 w-4 ms-auto hidden lg:block', locale === 'ar' && 'rotate-180')} />}
              </button>
            ))}
          </div>

          {/* بطاقة نصيحة */}
          <div className="hidden lg:block rounded-2xl border border-teal-100 bg-gradient-to-b from-teal-50/80 to-emerald-50/50 p-4">
            <Settings2 className="h-5 w-5 text-teal-600 mb-2" />
            <p className="text-xs font-bold text-teal-900">{t('نصيحة', 'Tip')}</p>
            <p className="text-[11px] text-teal-900/60 leading-relaxed mt-1">
              {t('كل تغيير تحفظه هنا ينعكس على صفحتك العامة فوراً — جرّب التصميم ثم انشرها.', 'Every change you save here reflects on your public page instantly — design, then publish.')}
            </p>
          </div>
        </aside>

        {/* المحتوى */}
        <div className="min-w-0 space-y-6">
          {tab === 'design' && <SiteDesignEditor onPreview={() => window.open(pageUrl, '_blank')} />}

          {tab === 'builder' && <PageBuilder onSaved={() => undefined} />}

          {tab === 'domain' && <DomainManager />}

          {tab === 'devices' && (
            <Card className="border-0 shadow-sm overflow-hidden">
              <CardContent className="p-6 sm:p-7 space-y-6">
                <div className="flex items-start gap-3.5">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500/10 to-emerald-500/10 border border-teal-100">
                    <MonitorSmartphone className="h-6 w-6 text-teal-700" />
                  </span>
                  <div>
                    <h2 className="font-bold text-lg">{t('التحكم بأجهزة الطلاب', 'Student device control')}</h2>
                    <p className="text-sm text-muted-foreground mt-1">{t('حدد عدد الأجهزة المسموح لكل طالب بالدخول منها — وإذا تعدى طالب الحد، أعد تعيين أجهزته من صفحة الطلاب', 'Set how many devices each student can log in from. Exceeded the limit? Reset from the Students page.')}</p>
                  </div>
                </div>

                {!policy ? <div className="flex justify-center py-8"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div> : (
                  <div className="grid sm:grid-cols-2 gap-5">
                    <div className={cn('p-5 rounded-2xl border transition-colors', policy.enabled ? 'border-teal-200 bg-teal-50/40' : 'border-slate-200 bg-slate-50/50')}>
                      <div className="flex items-center justify-between gap-3">
                        <div>
                          <p className="font-bold text-sm">{t('تفعيل سياسة الأجهزة', 'Enable device policy')}</p>
                          <p className="text-xs text-muted-foreground mt-1">{t('يمنع الطالب من الدخول من أكثر من العدد المسموح', 'Blocks students exceeding the allowed device count')}</p>
                        </div>
                        <Switch checked={policy.enabled} onCheckedChange={(v) => setPolicy({ ...policy, enabled: v })} label={t('تفعيل سياسة الأجهزة', 'Enable device policy')} />
                      </div>
                      <div className="mt-3 flex items-center gap-2 rounded-xl bg-white border border-slate-200 px-3 py-2 text-xs text-muted-foreground">
                        <Circle className={cn('h-2.5 w-2.5', policy.enabled ? 'text-emerald-500 fill-emerald-500' : 'text-slate-300 fill-slate-300')} />
                        {policy.enabled ? t('الحماية مفعلة الآن', 'Protection active now') : t('الحماية متوقفة', 'Protection paused')}
                      </div>
                    </div>
                    <div className="p-5 rounded-2xl border border-slate-200 bg-white">
                      <Label className="text-xs font-bold text-muted-foreground">{t('الحد الأقصى للأجهزة لكل طالب', 'Max devices per student')}</Label>
                      <div className="flex items-center gap-2.5 mt-2.5">
                        <div className="flex items-center rounded-xl border border-slate-200 bg-white overflow-hidden">
                          <button onClick={() => setPolicy({ ...policy, max_devices: Math.max(1, policy.max_devices - 1) })} className="h-11 w-11 flex items-center justify-center text-lg font-bold text-slate-400 hover:bg-slate-50 hover:text-teal-700 transition-colors" aria-label="minus">−</button>
                          <Input type="number" min={1} max={20} value={policy.max_devices} onChange={(e) => setPolicy({ ...policy, max_devices: Math.max(1, Number(e.target.value) || 1) })} className="h-11 w-16 border-0 text-center font-black text-lg focus-visible:ring-0" />
                          <button onClick={() => setPolicy({ ...policy, max_devices: Math.min(20, policy.max_devices + 1) })} className="h-11 w-11 flex items-center justify-center text-lg font-bold text-slate-400 hover:bg-slate-50 hover:text-teal-700 transition-colors" aria-label="plus">+</button>
                        </div>
                        <Button onClick={savePolicy} disabled={savingPolicy} className="gap-1.5 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                          {savingPolicy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Settings2 className="h-4 w-4" />}{t('حفظ', 'Save')}
                        </Button>
                      </div>
                      <p className="text-xs text-muted-foreground mt-3">{t('يمكنك إعطاء طالب معين حداً أعلى من صفحة الطلاب', 'You can grant a specific student a higher limit from the Students page')}</p>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          {tab === 'files' && (
            <Card className="border-0 shadow-sm overflow-hidden">
              <CardContent className="p-6 sm:p-7 space-y-6">
                <div className="flex items-start gap-3.5">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500/10 to-fuchsia-500/10 border border-violet-100">
                    <FileLock2 className="h-6 w-6 text-violet-600" />
                  </span>
                  <div>
                    <h2 className="font-bold text-lg">{t('التحكم بتنزيل ملفات الدروس', 'Lesson file download control')}</h2>
                    <p className="text-sm text-muted-foreground mt-1">{t('حدد ما إذا كان بإمكان طلابك تنزيل ملفات الدروس (PDF، صور...). عند التعطيل يبقى بإمكانهم معاينة الملفات القابلة للعرض فقط دون تنزيل', 'Choose whether students can download lesson files (PDF, images...). When disabled, they can only preview viewable files — no downloads.')}</p>
                  </div>
                </div>

                {!filePolicy ? <div className="flex justify-center py-8"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div> : (
                  <div className={cn('p-5 rounded-2xl border flex flex-wrap items-center justify-between gap-4 transition-colors', filePolicy.file_downloads_enabled ? 'border-emerald-200 bg-emerald-50/40' : 'border-violet-200 bg-violet-50/30')}>
                    <div>
                      <p className="font-bold text-sm">{t('السماح للطلاب بتنزيل الملفات', 'Allow students to download files')}</p>
                      <p className={cn('text-xs mt-1', filePolicy.file_downloads_enabled ? 'text-emerald-700' : 'text-violet-700')}>
                        {filePolicy.file_downloads_enabled
                          ? t('الطلاب يستطيعون تنزيل ملفات الدروس', 'Students can download lesson files')
                          : t('التنزيل معطل — المعاينة فقط للملفات المدعومة', 'Downloads blocked — preview only for supported files')}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      <Switch checked={filePolicy.file_downloads_enabled} onCheckedChange={(v) => setFilePolicy({ file_downloads_enabled: v })} label={t('السماح للطلاب بتنزيل الملفات', 'Allow students to download files')} />
                      <Button onClick={saveFilePolicy} disabled={savingFilePolicy} className="gap-1.5 rounded-full shrink-0 text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #7c3aed, #8b5cf6)' }}>
                        {savingFilePolicy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Settings2 className="h-4 w-4" />}{t('حفظ', 'Save')}
                      </Button>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          {tab === 'video' && (
            <Card className="border-0 shadow-sm overflow-hidden">
              <CardContent className="p-6 sm:p-7 space-y-6">
                <div className="flex items-start gap-3.5">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500/10 to-emerald-500/10 border border-teal-100">
                    <ShieldCheck className="h-6 w-6 text-teal-700" />
                  </span>
                  <div>
                    <h2 className="font-bold text-lg">{t('حماية الفيديو — العلامة المائية', 'Video security — watermark')}</h2>
                    <p className="text-sm text-muted-foreground mt-1">{t('عرض اسم الطالب كعلامة مائية متحركة أثناء مشاهدة فيديوهاتك — تتبع أي تسريب للمحتوى', 'Show the student name as an animated watermark while watching your videos — trace any leaked content')}</p>
                  </div>
                </div>

                {!videoSecurity ? (
                  <div className="flex justify-center py-8"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div>
                ) : !videoSecurity.feature_available ? (
                  <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-start gap-3">
                      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/15"><Crown className="h-5 w-5 text-amber-600" /></span>
                      <div>
                        <p className="font-bold text-sm text-amber-800">{t('ميزة العلامة المائية غير متاحة في خطتك الحالية', 'Watermark is not available in your current plan')}</p>
                        <p className="text-xs text-amber-700/70 mt-1">{t('قم بترقية خطتك لتفعيل حماية الفيديو بالعلامة المائية', 'Upgrade your plan to enable video watermark protection')}</p>
                      </div>
                    </div>
                    <Link href="/pricing">
                      <Button className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
                        <Crown className="h-4 w-4" />{t('ترقية الخطة', 'Upgrade plan')}
                      </Button>
                    </Link>
                  </div>
                ) : (
                  <>
                    <div className={`p-5 rounded-2xl border transition-colors ${videoSecurity.settings?.enabled ? 'border-teal-200 bg-teal-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                      <div className="flex items-center justify-between gap-3">
                        <div>
                          <p className="font-bold text-sm">{t('تشغيل العلامة المائية للطلاب', 'Enable student watermark')}</p>
                          <p className="text-xs text-muted-foreground mt-1">{t('اسم الطالب يظهر متحركاً بشفافية على الفيديو', 'The student name appears moving subtly over the video')}</p>
                        </div>
                        <Switch checked={!!videoSecurity.settings?.enabled} onCheckedChange={(v) => setVideoSecurity({ ...videoSecurity, settings: { ...videoSecurity.settings, enabled: v } })} label={t('تفعيل', 'Enable')} />
                      </div>
                    </div>

                    <div className="grid sm:grid-cols-2 gap-4">
                      <div className="p-4 rounded-2xl border border-slate-200 bg-white">
                        <div className="flex items-center justify-between gap-3 mb-3">
                          <p className="font-bold text-sm">{t('عرض رقم الطالب', 'Show student ID')}</p>
                          <Switch checked={!!videoSecurity.settings?.show_id} onCheckedChange={(v) => setVideoSecurity({ ...videoSecurity, settings: { ...videoSecurity.settings, show_id: v } })} label={t('معرف الحساب', 'Account ID')} />
                        </div>
                        <p className="text-xs text-muted-foreground">{t('إضافة معرف قصير بجانب الاسم (بدون بريد أو هاتف)', 'Add a short ID next to the name (no email or phone)')}</p>
                      </div>

                      <div className="p-4 rounded-2xl border border-slate-200 bg-white">
                        <p className="font-bold text-sm mb-3">{t('شفافية العلامة المائية', 'Watermark opacity')}</p>
                        <div className="flex gap-2">
                          {[['low', t('منخفضة', 'Low')], ['medium', t('متوسطة', 'Medium')], ['high', t('عالية', 'High')]].map(([v, l]) => (
                            <button key={v} onClick={() => setVideoSecurity({ ...videoSecurity, settings: { ...videoSecurity.settings, opacity: v } })}
                              className={`flex-1 px-3 py-2 rounded-lg border text-xs font-bold transition-all ${videoSecurity.settings?.opacity === v ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`}>
                              {l}
                            </button>
                          ))}
                        </div>
                      </div>

                      <div className="p-4 rounded-2xl border border-slate-200 bg-white">
                        <p className="font-bold text-sm mb-3">{t('سرعة الحركة', 'Movement speed')}</p>
                        <div className="flex gap-2">
                          {[['slow', t('بطيئة', 'Slow')], ['medium', t('متوسطة', 'Medium')], ['fast', t('سريعة', 'Fast')]].map(([v, l]) => (
                            <button key={v} onClick={() => setVideoSecurity({ ...videoSecurity, settings: { ...videoSecurity.settings, speed: v } })}
                              className={`flex-1 px-3 py-2 rounded-lg border text-xs font-bold transition-all ${videoSecurity.settings?.speed === v ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`}>
                              {l}
                            </button>
                          ))}
                        </div>
                      </div>

                      <div className="p-4 rounded-2xl border border-slate-200 bg-white">
                        <p className="font-bold text-sm mb-3">{t('حجم النص', 'Text size')}</p>
                        <div className="flex gap-2">
                          {[['small', t('صغير', 'Small')], ['medium', t('متوسط', 'Medium')], ['large', t('كبير', 'Large')]].map(([v, l]) => (
                            <button key={v} onClick={() => setVideoSecurity({ ...videoSecurity, settings: { ...videoSecurity.settings, size: v } })}
                              className={`flex-1 px-3 py-2 rounded-lg border text-xs font-bold transition-all ${videoSecurity.settings?.size === v ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`}>
                              {l}
                            </button>
                          ))}
                        </div>
                      </div>
                    </div>

                    <Button onClick={async () => {
                      setSavingVideo(true);
                      try {
                        const res = await fetch(`${API}/teacher/video-security`, { method: 'PUT', headers: headers(), body: JSON.stringify(videoSecurity.settings) });
                        if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('فشل الحفظ', 'Save failed')); return; }
                        const d = await res.json();
                        setVideoSecurity({ ...videoSecurity, settings: d.settings });
                        toast.success(t('تم حفظ إعدادات حماية الفيديو — تطبق فوراً على كل الفيديوهات', 'Video security saved — applies to all videos instantly'));
                      } catch { toast.error(t('فشل الحفظ', 'Save failed')); }
                      setSavingVideo(false);
                    }} disabled={savingVideo} className="gap-2 rounded-full text-white shadow-brand w-full sm:w-auto" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                      {savingVideo ? <Loader2 className="h-4 w-4 animate-spin" /> : <ShieldCheck className="h-4 w-4" />}{t('حفظ الإعدادات', 'Save settings')}
                    </Button>
                  </>
                )}
              </CardContent>
            </Card>
          )}

          {tab === 'share' && (
            <div className="space-y-6">
            <div className="grid md:grid-cols-2 gap-6">
              {/* QR */}
              <Card className="border-0 shadow-sm overflow-hidden">
                <CardContent className="p-6 flex flex-col items-center text-center">
                  <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-500/10 mb-4">
                    <QrCode className="h-5.5 w-5.5 text-teal-600" />
                  </span>
                  <h2 className="font-bold">{t('رمز QR لصفحتك', 'Your page QR code')}</h2>
                  <p className="text-xs text-muted-foreground mt-1 mb-5">{t('اطبعه أو ضعه في إعلاناتك — الطلاب يمسحون ويدخلون فوراً', 'Print it or put it in your ads — students scan and enter instantly')}</p>
                  <div className="rounded-2xl border-2 border-dashed border-teal-200 bg-white p-5 shadow-inner">
                    {qr ? (
                      <img src={qr} alt="QR" className="w-44 h-44" />
                    ) : (
                      <div className="w-44 h-44 flex items-center justify-center"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div>
                    )}
                  </div>
                  <Button variant="outline" className="gap-2 rounded-full mt-5" onClick={() => { const a = document.createElement('a'); a.href = qr || ''; a.download = 'page-qr.png'; a.click(); }} disabled={!qr}>
                    <FileLock2 className="h-4 w-4 text-teal-700" /> {t('تحميل QR', 'Download QR')}
                  </Button>
                </CardContent>
              </Card>

              {/* روابط المشاركة */}
              <Card className="border-0 shadow-sm overflow-hidden">
                <CardContent className="p-6 space-y-4">
                  <div className="flex items-center gap-2.5">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/10">
                      <Share2 className="h-5 w-5 text-teal-600" />
                    </span>
                    <div>
                      <h2 className="font-bold text-sm">{t('شارك صفحتك', 'Share your page')}</h2>
                      <p className="text-[11px] text-muted-foreground">{t('انشر رابطك في قنواتك المفضلة', 'Spread your link on your favorite channels')}</p>
                    </div>
                  </div>

                  {/* حقل الرابط */}
                  <div className="flex items-center gap-2 rounded-2xl border border-teal-100 bg-teal-50/50 p-2 ps-4">
                    <Link2 className="h-4 w-4 text-teal-600 shrink-0" />
                    <span className="flex-1 min-w-0 font-mono text-xs text-teal-800 truncate" dir="ltr">{pageUrl || '—'}</span>
                    <Button size="sm" className="gap-1.5 rounded-full shrink-0" onClick={copyUrl} disabled={!pageUrl} style={{ background: '#0d9488' }}>
                      {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}{copied ? t('تم', 'Done') : t('نسخ', 'Copy')}
                    </Button>
                  </div>

                  <div className="grid grid-cols-3 gap-2.5">
                    <button onClick={() => share('whatsapp')} disabled={!pageUrl} className="group flex flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-white p-4 transition-all hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md disabled:opacity-40">
                      <span className="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-500/10 group-hover:bg-emerald-500/20 transition-colors">
                        <MessageCircle className="h-5 w-5 text-emerald-600" />
                      </span>
                      <span className="text-xs font-bold">WhatsApp</span>
                    </button>
                    <button onClick={() => share('telegram')} disabled={!pageUrl} className="group flex flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-white p-4 transition-all hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md disabled:opacity-40">
                      <span className="flex h-11 w-11 items-center justify-center rounded-full bg-sky-500/10 group-hover:bg-sky-500/20 transition-colors">
                        <Send className="h-5 w-5 text-sky-600" />
                      </span>
                      <span className="text-xs font-bold">Telegram</span>
                    </button>
                    <button onClick={() => share('x')} disabled={!pageUrl} className="group flex flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-white p-4 transition-all hover:-translate-y-0.5 hover:border-slate-400 hover:shadow-md disabled:opacity-40">
                      <span className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-500/10 group-hover:bg-slate-500/20 transition-colors">
                        <Twitter className="h-5 w-5 text-slate-600" />
                      </span>
                      <span className="text-xs font-bold">X</span>
                    </button>
                  </div>

                  <div className="rounded-2xl bg-gradient-to-l from-teal-50 to-emerald-50 border border-teal-100/70 p-4 flex items-start gap-3">
                    <Sparkles className="h-5 w-5 text-teal-600 shrink-0 mt-0.5" />
                    <p className="text-xs text-teal-900/70 leading-relaxed">
                      <b>{t('تلميح: ', 'Hint: ')}</b>
                      {t('شارك الرابط مع طلابك على واتساب أو اطبع رمز QR وضعيه في كتابك أو مكتبتك — كل مسحة تسجل طالباً جديداً.', 'Share the link with students on WhatsApp, or print the QR and place it in your book or library — every scan is a new student.')}
                    </p>
                  </div>
                </CardContent>
              </Card>
            </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
