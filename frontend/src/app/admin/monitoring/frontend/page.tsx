'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Bug, Loader2, RefreshCw, ChevronDown, Clock, Globe, Search, Copy, Hash,
  MapPin, MonitorSmartphone, AlertCircle, Inbox,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const TYPE_LABELS: Record<string, [string, string]> = {
  error: ['خطأ جافاسكربت', 'JS Error'],
  unhandledrejection: ['وعد مرفوض', 'Unhandled rejection'],
  http: ['خطأ طلب شبكة', 'HTTP error'],
  proxy: ['خطأ وسيط السيرفر', 'Proxy error'],
  console: ['رسالة وحدة التحكم', 'Console'],
};

export default function AdminFrontendErrorsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [errors, setErrors] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [openId, setOpenId] = useState<any>(null);

  const headers = () => ({ 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  const load = useCallback(() => {
    setLoading(true);
    fetch(`${API}/admin/monitoring/frontend-errors?limit=100`, { headers: headers() })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((d) => { setErrors(d.errors || []); setTotal(d.total || (d.errors || []).length); })
      .catch(() => toast.error(t('تعذر تحميل الأخطاء', 'Failed to load errors')))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { if (check()) load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const typeLabel = (type: string) => {
    const m = TYPE_LABELS[type];
    return m ? t(m[0], m[1]) : (type || t('خطأ', 'Error'));
  };

  const fmtFull = (iso?: string) => {
    if (!iso) return '—';
    const d = new Date(iso);
    try {
      return d.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { dateStyle: 'full', timeStyle: 'medium' });
    } catch { return d.toLocaleString(); }
  };

  const copyError = async (e: any) => {
    const text = [
      `#${e.id} ${typeLabel(e.type)}`,
      `message: ${e.message || ''}`,
      `url: ${e.url || ''}`,
      `method: ${e.method || ''}`,
      `status: ${e.status ?? ''}`,
      `time: ${e.created_at || ''}`,
      `app: ${e.app || ''}`,
      `stack_hash: ${e.stack_hash || ''}`,
      `metadata: ${JSON.stringify(e.metadata || {})}`,
    ].join('\n');
    try { await navigator.clipboard.writeText(text); toast.success(t('تم نسخ تفاصيل الخطأ', 'Error details copied')); }
    catch { toast.error(t('تعذر النسخ', 'Cannot copy')); }
  };

  const q = search.trim().toLowerCase();
  const filtered = q
    ? errors.filter((e) => `${e.name || ''} ${e.message || ''} ${e.url || ''} ${e.type || ''}`.toLowerCase().includes(q))
    : errors;

  return (
    <div className="space-y-4 max-w-6xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#dc262612' }}>
            <Bug className="h-6 w-6" style={{ color: '#dc2626' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('أخطاء الواجهة', 'Frontend Errors')}</h1>
            <p className="text-muted-foreground mt-1">{t('كل خطأ باسمه وتفاصيله ومكانه ووقته بالضبط', 'Every error by name, detail, location and exact time')}</p>
          </div>
        </div>
        <Button variant="outline" className="gap-2 rounded-full" onClick={load} disabled={loading}>
          {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}{t('تحديث', 'Refresh')}
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[240px] max-w-md">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('بحث في اسم الخطأ أو الرسالة أو الرابط...', 'Search by name, message or URL...')}
            className="ps-9 h-10"
          />
        </div>
        <Badge variant="secondary" className="rounded-full">{t('إجمالي', 'Total')}: {total}</Badge>
        <Badge variant="outline" className="rounded-full">{t('ظاهر', 'Shown')}: {filtered.length}</Badge>
      </div>

      {loading ? (
        <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
      ) : filtered.length === 0 ? (
        <Card><CardContent className="flex flex-col items-center gap-3 py-14 text-center text-muted-foreground">
          <Inbox className="h-10 w-10 opacity-40" />
          <p className="text-sm">{errors.length === 0 ? t('لا توجد أخطاء مسجلة — كل شيء يعمل 👌', 'No errors recorded — all good 👌') : t('لا نتائج مطابقة للبحث', 'No results match your search')}</p>
        </CardContent></Card>
      ) : (
        <div className="space-y-2.5">
          {filtered.map((e, i) => {
            const open = openId === (e.id ?? i);
            const isServer = e.status && e.status >= 500;
            return (
              <Card key={e.id ?? i} className={`border-0 shadow-sm overflow-hidden transition-all ${open ? 'ring-1 ring-teal-200' : 'hover:shadow-md'}`}>
                <button
                  type="button"
                  onClick={() => setOpenId(open ? null : (e.id ?? i))}
                  className="w-full text-start p-4 flex flex-wrap items-center gap-3"
                  aria-expanded={open}
                >
                  <Badge variant={isServer ? 'default' : 'secondary'} className="shrink-0">{typeLabel(e.type)}</Badge>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold break-words">{e.name || e.message || t('(بدون رسالة)', '(no message)')}</p>
                    <p className="text-xs text-muted-foreground truncate mt-0.5 flex items-center gap-1" dir="ltr">
                      <MapPin className="h-3 w-3 shrink-0" />{e.url || '—'}
                    </p>
                  </div>
                  {e.status && <Badge variant="outline" className="shrink-0">{e.status}</Badge>}
                  <span className="text-xs text-muted-foreground shrink-0 whitespace-nowrap inline-flex items-center gap-1">
                    <Clock className="h-3 w-3" />{e.created_at ? new Date(e.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US') : ''}
                  </span>
                  <ChevronDown className={`h-4 w-4 shrink-0 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>

                {open && (
                  <div className="border-t border-teal-50 bg-teal-50/30 px-4 py-4 space-y-3.5 text-sm">
                    <div>
                      <div className="text-xs font-bold text-gray-500 mb-1">{t('الرسالة الكاملة', 'Full message')}</div>
                      <pre className="whitespace-pre-wrap break-words rounded-xl bg-white border border-teal-100 p-3 text-[13px] leading-relaxed" dir="ltr">{e.message || '—'}</pre>
                    </div>

                    <div className="grid sm:grid-cols-2 gap-3">
                      <DetailRow icon={MapPin} label={t('مكان الخطأ', 'Where it happened')} value={e.url || '—'} ltr />
                      <DetailRow icon={MonitorSmartphone} label={t('الأسلوب / الحالة', 'Method / status')} value={`${e.method || 'GET'} ${e.status ?? ''}`.trim()} ltr />
                      <DetailRow icon={Clock} label={t('الوقت بالضبط', 'Exact time')} value={fmtFull(e.created_at)} />
                      <DetailRow icon={Globe} label={t('التطبيق / اللغة', 'App / locale')} value={`${e.app || 'web'}${e.locale ? ' · ' + e.locale : ''}`} ltr />
                      <DetailRow icon={Hash} label={t('بصمة التتبع', 'Stack hash')} value={e.stack_hash || '—'} ltr />
                      <DetailRow icon={AlertCircle} label={t('المعرّف', 'ID')} value={`#${e.id}`} ltr />
                    </div>

                    {e.user_agent && (
                      <div>
                        <div className="text-xs font-bold text-gray-500 mb-1">{t('متصفح المستخدم', 'User agent')}</div>
                        <p className="rounded-xl bg-white border border-teal-100 p-3 text-xs text-gray-600 break-words" dir="ltr">{e.user_agent}</p>
                      </div>
                    )}

                    {e.metadata && Object.keys(e.metadata).length > 0 && (
                      <div>
                        <div className="text-xs font-bold text-gray-500 mb-1">{t('سياق إضافي', 'Extra context')}</div>
                        <div className="rounded-xl bg-white border border-teal-100 p-3 space-y-1" dir="ltr">
                          {Object.entries(e.metadata).map(([k, v]) => (
                            <div key={k} className="flex gap-2 text-xs"><span className="text-gray-400 font-mono shrink-0">{k}:</span><span className="text-gray-700 break-words">{String(v)}</span></div>
                          ))}
                        </div>
                      </div>
                    )}

                    <div className="flex justify-end">
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full" onClick={() => copyError(e)}>
                        <Copy className="h-3.5 w-3.5" />{t('نسخ التفاصيل', 'Copy details')}
                      </Button>
                    </div>
                  </div>
                )}
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}

function DetailRow({ icon: Icon, label, value, ltr }: { icon: any; label: string; value: string; ltr?: boolean }) {
  return (
    <div className="flex items-start gap-2 rounded-xl bg-white border border-teal-100 p-3">
      <Icon className="h-4 w-4 shrink-0 text-teal-600 mt-0.5" />
      <div className="min-w-0">
        <div className="text-[11px] font-bold text-gray-500">{label}</div>
        <div className="text-[13px] break-words" dir={ltr ? 'ltr' : undefined}>{value}</div>
      </div>
    </div>
  );
}
