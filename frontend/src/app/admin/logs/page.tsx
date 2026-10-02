'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { Loader2, ShieldCheck, Search, Globe, AlertTriangle, Trash2, Eraser, Bug, AlertCircle } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const ACTION_LABELS: Record<string, [string, string]> = {
  'auth.login': ['دخول', 'Login'],
  'auth.login_2fa': ['دخول (2FA)', 'Login (2FA)'],
  'auth.logout': ['خروج', 'Logout'],
  'auth.register': ['تسجيل', 'Register'],
  'auth.2fa_failed': ['فشل 2FA', '2FA failed'],
  '2fa.enabled': ['تفعيل 2FA', '2FA enabled'],
  '2fa.disabled': ['تعطيل 2FA', '2FA disabled'],
  '2fa.enable_failed': ['فشل تفعيل 2FA', '2FA enable failed'],
  'card.redeemed': ['تفعيل بطاقة', 'Card redeemed'],
  'subscription.subscribed': ['اشتراك', 'Subscribed'],
  'settings.updated': ['تعديل إعدادات', 'Settings updated'],
  'site.design_updated': ['تعديل تصميم الموقع', 'Site design updated'],
  'backup.created': ['نسخ احتياطي', 'Backup created'],
  'backup.deleted': ['حذف نسخة', 'Backup deleted'],
};

export default function AdminLogsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'audit' | 'errors'>('audit');
  const [logs, setLogs] = useState<any[]>([]);
  const [errors, setErrors] = useState<any[]>([]);
  const [errorStats, setErrorStats] = useState<any>({});
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<any>(null);
  const [clearing, setClearing] = useState(false);
  const [error, setError] = useState('');

  const headers = () => ({ 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  useEffect(() => {
    if (!check()) return;
    if (tab === 'audit') loadAudit();
    else loadErrors();
  }, [check(), tab]);

  const loadAudit = () => {
    setLoading(true);
    fetch(`${API}/audit-logs?search=${encodeURIComponent(search)}`, { headers: headers() })
      .then(r => r.json()).then(d => { setLogs(d.logs || []); setError(''); })
      .catch(() => setError(t('تعذر تحميل سجلات التدقيق', 'Failed to load audit logs')))
      .finally(() => setLoading(false));
  };

  const loadErrors = () => {
    setLoading(true);
    fetch(`${API}/admin/error-logs?search=${encodeURIComponent(search)}`, { headers: headers() })
      .then(r => r.json()).then(d => {
        setErrors(d.logs?.data || []);
        setErrorStats(d.stats || {});
        setError('');
      }).catch(() => setError(t('تعذر تحميل سجلات الأخطاء', 'Failed to load error logs')))
      .finally(() => setLoading(false));
  };

  const clearErrors = async () => {
    if (!window.confirm(t('مسح كل سجلات الأخطاء؟', 'Clear all error logs?'))) return;
    setClearing(true);
    try { await fetch(`${API}/admin/error-logs/clear`, { method: 'POST', headers: headers() }); loadErrors(); } catch { /* */ }
    setClearing(false);
  };

  const deleteError = async (id: string) => {
    if (!window.confirm(t('حذف سجل الخطأ هذا؟', 'Delete this error log?'))) return;
    try { await fetch(`${API}/admin/error-logs/${id}`, { method: 'DELETE', headers: headers() }); loadErrors(); } catch { /* */ }
  };

  const label = (a: string) => {
    const l = ACTION_LABELS[a] || [a, a];
    return locale === 'ar' ? l[0] : l[1];
  };

  const color = (a: string) => {
    if (a.includes('failed') || a.includes('logout')) return 'bg-red-50 text-red-600';
    if (a.includes('login') || a.includes('2fa')) return 'bg-teal-50 text-teal-700';
    if (a.includes('card') || a.includes('subscription')) return 'bg-green-50 text-green-600';
    if (a.includes('settings') || a.includes('site') || a.includes('backup')) return 'bg-violet-50 text-violet-600';
    return 'bg-gray-100 text-gray-600';
  };

  const levelColor = (lv: string) => lv === 'error' ? 'bg-red-50 text-red-600' : lv === 'warning' ? 'bg-amber-50 text-amber-600' : 'bg-gray-50 text-gray-500';

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#dc262612' }}>
            <ShieldCheck className="h-6 w-6" style={{ color: '#dc2626' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('السجلات', 'Logs')}</h1>
            <p className="text-muted-foreground mt-1">{t('سجلات التدقيق والأخطاء في المنصة', 'Audit trail & error logs')}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setTab('audit')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all ${tab === 'audit' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600 border border-gray-200'}`}>
            <ShieldCheck className="h-4 w-4" /> {t('سجلات التدقيق', 'Audit')}
          </button>
          <button onClick={() => setTab('errors')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all ${tab === 'errors' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 border border-gray-200'}`}>
            <Bug className="h-4 w-4" /> {t('سجلات الأخطاء', 'Errors')}
            {errorStats?.total > 0 && <Badge className="bg-red-100 text-red-700 border-0">{errorStats.total}</Badge>}
          </button>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative max-w-md flex-1">
          <Search className="h-4 w-4 absolute start-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { setLoading(true); tab === 'audit' ? loadAudit() : loadErrors(); } }}
            placeholder={tab === 'audit' ? t('ابحث بالاسم أو الإيميل...', 'Search by name or email...') : t('ابحث في الأخطاء...', 'Search errors...')} className="h-11 ps-10 rounded-full" />
        </div>
        {tab === 'errors' && errorStats?.total > 0 && (
          <Button variant="destructive" onClick={clearErrors} disabled={clearing} className="gap-2">
            {clearing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Eraser className="h-4 w-4" />} {t('مسح الكل', 'Clear all')}
          </Button>
        )}
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={4} rows={6} /></CardContent></Card> :
        tab === 'audit' ? (
          logs.length === 0 ? (
            <EmptyState icon={ShieldCheck} title={t('لا توجد أحداث', 'No events yet')} description={t('ستظهر هنا أحداث التدقيق عند حدوثها', 'Audit events will appear here as they happen')} />
          ) : (
            <Card className="border-0 shadow-sm overflow-hidden">
              <div className="divide-y">
                {logs.map((l) => (
                  <div key={l.id} className="flex items-center gap-4 p-4 hover:bg-gray-50/60">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 shrink-0 text-teal-700 font-bold text-xs">{l.user_name?.[0]?.toUpperCase() || '?'}</div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <Badge className={`border-0 text-[10px] ${color(l.action)}`}>{label(l.action)}</Badge>
                        {l.entity_type && <span className="text-[10px] text-gray-400">{l.entity_type}{l.entity_id ? ` · ${l.entity_id.slice(0, 8)}` : ''}</span>}
                      </div>
                      <p className="text-sm font-medium mt-1">{l.user_name || '—'} <span className="text-gray-400 font-normal">({l.user_email || '—'})</span></p>
                      {l.details && Object.keys(l.details).length > 0 && (
                        <p className="text-[11px] text-gray-400 truncate">{JSON.stringify(l.details)}</p>
                      )}
                    </div>
                    <div className="text-end shrink-0">
                      <p className="text-xs text-gray-500">{l.created_at ? new Date(l.created_at).toLocaleString() : ''}</p>
                      <p className="flex items-center gap-1 text-[10px] text-gray-400 mt-1 justify-end"><Globe className="h-3 w-3" />{l.ip_address || '—'}</p>
                    </div>
                  </div>
                ))}
              </div>
            </Card>
          )
        ) : (
          errors.length === 0 ? (
            <EmptyState icon={AlertTriangle} title={t('لا توجد أخطاء — ممتاز!', 'No errors — great!')} description={t('لم تُسجل أي أخطاء في المنصة', 'No errors recorded on the platform')} />
          ) : (
            <Card className="border-0 shadow-sm overflow-hidden">
              <div className="divide-y">
                {errors.map((e) => (
                  <div key={e.id} className="flex items-center gap-4 p-4 hover:bg-gray-50/60">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 shrink-0 text-red-600"><AlertTriangle className="h-5 w-5" /></div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <Badge className={`border-0 text-[10px] ${levelColor(e.level)}`}>{e.level}</Badge>
                        {e.source && <Badge className="bg-gray-50 text-gray-500 border-0 text-[10px]">{e.source}</Badge>}
                      </div>
                      <p className="text-sm font-medium mt-1 truncate">{e.message}</p>
                      <p className="text-[11px] text-gray-400">{e.url || ''} {e.ip_address ? `· ${e.ip_address}` : ''}</p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <Button size="sm" variant="outline" onClick={() => setDetail(e)}>{t('تفاصيل', 'Details')}</Button>
                      <button onClick={() => deleteError(e.id)} className="p-1.5 rounded-lg text-red-400 hover:bg-red-50" aria-label={t('حذف السجل', 'Delete log')}><Trash2 className="h-4 w-4" /></button>
                    </div>
                    <p className="text-xs text-gray-500 shrink-0 w-32 text-end">{new Date(e.created_at).toLocaleString()}</p>
                  </div>
                ))}
              </div>
            </Card>
          )
        )}

      <Dialog open={!!detail} onOpenChange={(v) => { if (!v) setDetail(null); }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader><DialogTitle>{t('تفاصيل الخطأ', 'Error details')}</DialogTitle></DialogHeader>
          {detail && (
            <div className="space-y-4 text-sm">
              <div className="rounded-xl bg-red-50 p-3"><b>{detail.level}</b> — {detail.message}</div>
              {detail.context?.file && <p className="text-gray-500">{detail.context.file}:{detail.context.line}</p>}
              {detail.url && <p className="text-gray-500">{t('الرابط:', 'URL:')} {detail.url}</p>}
              {detail.ip_address && <p className="text-gray-500">{t('العنوان:', 'IP:')} {detail.ip_address}</p>}
              {detail.user_agent && <p className="text-gray-500 text-xs break-words">{detail.user_agent}</p>}
              {detail.context?.trace && (
                <details className="rounded-xl border border-gray-100 p-3">
                  <summary className="cursor-pointer font-medium">{t('مسار الاستدعاء', 'Stack trace')}</summary>
                  <pre className="mt-3 text-[10px] overflow-x-auto max-h-60 text-gray-500">{JSON.stringify(detail.context.trace, null, 1)}</pre>
                </details>
              )}
              <DialogFooter>
                <Button variant="outline" onClick={() => setDetail(null)}>{t('إغلاق', 'Close')}</Button>
              </DialogFooter>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
