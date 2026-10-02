'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { ServerCog, HardDrive, Film, CircleDollarSign, Activity, RefreshCw, Wifi, Loader2, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminMediaPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [credForm, setCredForm] = useState<any>({});
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [syncing, setSyncing] = useState(false);

  const headers = () => ({ Accept: 'application/json' });

  const load = async () => {
    try {
      const res = await fetch(`${API}/admin/media`, { headers: headers() });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setData(d);
      const p = d.providers?.bunny;
      if (p?.credentials && Object.keys(p.credentials).length > 0) {
        setCredForm(p.credentials);
      }
    } catch { setError(t('تعذر تحميل البيانات', 'Failed to load data')); }
    finally { setLoading(false); }
  };

  useEffect(() => { if (check()) load(); }, [check]);

  const prov = data?.providers?.bunny || {};
  const s = data?.summary || {};

  const saveCredentials = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${API}/admin/media/providers/bunny`, {
        method: 'PUT',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ credentials: credForm, enabled: true }),
      });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحفظ — جارٍ اختبار الاتصال…', 'Saved — testing connection…'));
      load();
    } catch { toast.error(t('فشل الحفظ', 'Save failed')); }
    finally { setSaving(false); }
  };

  const testProvider = async () => {
    setTesting(true);
    try {
      const res = await fetch(`${API}/admin/media/providers/bunny/test`, { method: 'POST', headers: headers() });
      const d = await res.json().catch(() => ({}));
      if (res.ok && d.ok) {
        toast.success(d.message || t('الاتصال ممتاز — المزود جاهز', 'Connection OK — provider ready'));
      } else {
        toast.error(d.message || t('فشل الاتصال', 'Connection failed'));
      }
      load();
    } catch { toast.error(t('فشل الاختبار', 'Test failed')); }
    finally { setTesting(false); }
  };

  const syncNow = async () => {
    setSyncing(true);
    try {
      await fetch(`${API}/admin/media/providers/bunny/sync`, { method: 'POST', headers: headers() });
      toast.success(t('تمت المزامنة', 'Synced'));
      load();
    } catch { toast.error(t('فشلت المزامنة', 'Sync failed')); }
    finally { setSyncing(false); }
  };

  const statusColor = (st?: string) =>
    st === 'healthy' ? 'bg-green-100 text-green-700' : st === 'error' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';

  if (loading) {
    return <div className="max-w-7xl mx-auto p-6"><Card><CardContent className="p-6"><TableSkeleton cols={3} rows={4} /></CardContent></Card></div>;
  }

  if (!data) {
    return (
      <div className="max-w-7xl mx-auto p-6 space-y-4">
        <h1 className="text-2xl sm:text-3xl font-bold">{t('الوسائط والبنية التحتية', 'Media Infrastructure')}</h1>
        {error && <Alert variant="error" icon={Activity}>{error}</Alert>}
        <EmptyState icon={ServerCog} title={t('لا توجد بيانات', 'No data')} />
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <ServerCog className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الوسائط والبنية التحتية', 'Media Infrastructure')}</h1>
            <p className="text-muted-foreground mt-1">{t('Bunny Stream — الفيديوهات المسجلة', 'Bunny Stream — recorded videos')}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <button onClick={testProvider} disabled={testing} className="flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
            {testing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Wifi className="h-4 w-4" />} {t('فحص الاتصال', 'Test')}
          </button>
          <button onClick={syncNow} disabled={syncing} className="flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
            {syncing ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />} {t('مزامنة', 'Sync')}
          </button>
        </div>
      </div>

      {/* ─── ملخص سريع ─────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: t('التخزين', 'Storage'), value: `${s?.storage_gb ?? 0} GB`, icon: HardDrive, color: '#0d9488' },
          { label: t('الفيديوهات', 'Videos'), value: String(s?.videos ?? 0), icon: Film, color: '#7c3aed' },
          { label: t('تكلفة الشهر', 'Monthly cost'), value: `${s?.cost?.total ?? 0} USD`, icon: CircleDollarSign, color: '#dc2626' },
          { label: t('المزود', 'Provider'), value: prov?.label || 'Bunny Stream', icon: ServerCog, color: '#0891b2' },
        ].map((c, i) => (
          <Card key={i} className="border-0 shadow-sm"><CardContent className="p-5">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl mb-3" style={{ backgroundColor: `${c.color}15` }}><c.icon className="h-5 w-5" style={{ color: c.color }} /></div>
            <div className="text-xl font-bold">{c.value}</div>
            <div className="text-sm text-gray-500 mt-0.5">{c.label}</div>
          </CardContent></Card>
        ))}
      </div>

      {/* ─── المزود ─────────────────────────────── */}
      <Card className="border-0 shadow-sm"><CardContent className="p-6 space-y-4">
        <div className="flex items-center gap-2">
          <ServerCog className="h-5 w-5 text-teal-700" />
          <h3 className="font-bold">{prov?.label || 'Bunny Stream'}</h3>
          <Badge className={statusColor(prov?.connection_status)}>{prov?.connection_status === 'healthy' ? t('متصل', 'Connected') : prov?.configured ? t('مكوّن', 'Configured') : t('غير مكوّن', 'Not configured')}</Badge>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
          <div><p className="text-[11px] text-gray-500">{t('الحالة', 'Enabled')}</p><p className="font-semibold">{prov?.enabled ? t('مفعّل', 'Enabled') : t('معطل', 'Disabled')}</p></div>
          <div><p className="text-[11px] text-gray-500">{t('آخر فحص', 'Last check')}</p><p className="font-semibold">{prov?.last_health_check_at ? new Date(prov.last_health_check_at).toLocaleTimeString() : '—'}</p></div>
          <div><p className="text-[11px] text-gray-500">{t('آخر مزامنة', 'Last sync')}</p><p className="font-semibold">{prov?.last_synced_at ? new Date(prov.last_synced_at).toLocaleTimeString() : '—'}</p></div>
          <div><p className="text-[11px] text-gray-500">{t('ويبهوك', 'Webhook')}</p><p className="font-semibold">{prov?.webhook_secret_set ? '✓' : '—'}</p></div>
        </div>

        {prov?.last_error && <Alert variant="error" icon={Activity}>{prov.last_error}</Alert>}

        <div className="border-t border-slate-100 pt-4">
          <p className="mb-2 text-sm font-bold">{t('الاعتمادات (مشفرة)', 'Credentials (encrypted)')}</p>
          <div className="space-y-2.5">
            {[
              ['library_id', 'Library ID'],
              ['api_key', 'API Key'],
              ['hostname', 'Hostname'],
            ].map(([k, label]) => (
              <div key={k} className="flex items-center gap-2">
                <label className="w-32 shrink-0 text-[11px] font-semibold text-gray-500">{label}</label>
                <input
                  type="text"
                  dir="ltr"
                  placeholder={k}
                  value={credForm[k] || ''}
                  onChange={e => setCredForm({ ...credForm, [k]: e.target.value })}
                  className="flex-1 rounded-xl border border-input px-3 py-2 text-sm font-mono outline-none focus:ring-2 focus:ring-ring"
                />
              </div>
            ))}
          </div>
          <div className="mt-3 flex gap-2">
            <button onClick={saveCredentials} disabled={saving} className="rounded-full bg-teal-600 px-5 py-2 text-sm font-bold text-white hover:bg-teal-700 disabled:opacity-50">
              {saving ? <Loader2 className="h-4 w-4 animate-spin inline" /> : null} {t('حفظ', 'Save')}
            </button>
          </div>
        </div>
      </CardContent></Card>

      {/* ─── ويبهوك (مبسط) ──────────────────────── */}
      <Card className="border-0 shadow-sm"><CardContent className="p-6">
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-bold flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-teal-700" />{t('أحداث الويبهوك', 'Webhook events')}</h3>
          <div className="flex gap-2 text-xs">
            <Badge className="bg-green-100 text-green-700">{t('معالجة', 'Processed')}: {data?.webhooks?.processed ?? 0}</Badge>
            <Badge className="bg-red-100 text-red-700">{t('فشل', 'Failed')}: {data?.webhooks?.failed ?? 0}</Badge>
          </div>
        </div>
        <div className="space-y-2">
          {(data?.recent_webhook_events || []).length === 0 && <p className="text-sm text-gray-400">{t('لا أحداث بعد', 'No events yet')}</p>}
          {(data?.recent_webhook_events || []).slice(0, 6).map((e: any) => (
            <div key={e.id} className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3 py-2 text-sm">
              <span className="font-mono text-xs truncate">{e.event_type}</span>
              <div className="flex items-center gap-2 shrink-0">
                <Badge className={e.status === 'processed' ? 'bg-green-100 text-green-700' : e.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}>{e.status}</Badge>
                <span className="text-[11px] text-gray-400">{e.received_at ? new Date(e.received_at).toLocaleTimeString() : ''}</span>
              </div>
            </div>
          ))}
        </div>
      </CardContent></Card>
    </div>
  );
}