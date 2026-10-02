'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Loader2, Activity, Database, Cpu, MemoryStick, HardDrive, ShieldAlert, Timer, Boxes, AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminMonitoringPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const headers = () => ({ 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  const load = () => {
    setLoading(true);
    fetch(`${API}/admin/monitoring/summary`, { headers: headers() })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(setData)
      .catch((e) => setError(String(e)))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!check()) return;
    load();
    const i = setInterval(load, 30000);
    return () => clearInterval(i);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const levelBadge = (level: string) =>
    level === 'critical' ? <Badge className="bg-rose-600 text-white">CRITICAL</Badge> : <Badge className="bg-amber-500 text-white">WARNING</Badge>;

  const statusBadge = (status: string) =>
    status === 'ok' || status === 'healthy' || status === 'running' ? (
      <Badge className="bg-emerald-600 text-white"><CheckCircle2 className="h-3 w-3 me-1" /> OK</Badge>
    ) : (
      <Badge className="bg-rose-600 text-white"><XCircle className="h-3 w-3 me-1" /> {status}</Badge>
    );

  const stat = (label: string, value: string | number | undefined, sub?: string) => (
    <div className="rounded-2xl border bg-muted/30 p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-xl font-bold">{value ?? '—'}</p>
      {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
    </div>
  );

  if (loading && !data) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  const host = data?.host || {};
  const db = data?.database || {};
  const redis = data?.redis || {};
  const queue = data?.queue || {};
  const backup = data?.backup || {};
  const storage = data?.storage || {};
  const security = data?.security || {};
  const app = data?.app || {};
  const alerts = data?.alerts || [];

  const mb = (b?: number) => (b == null ? '—' : `${Math.round(b / 1048576)} MB`);
  const pct = (n?: number) => (n == null ? '—' : `${n}%`);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('مراقبة النظام', 'System Monitoring')}</h1>
          <p className="text-sm text-muted-foreground mt-1">
            {t('لقطة الجمع: ', 'Snapshot: ')}
            {data.snapshot_at ? new Date(data.snapshot_at).toLocaleString(locale === 'ar' ? 'ar' : 'en-US') : '—'}
          </p>
        </div>
        {statusBadge(data.status)}
      </div>

      {error && <div className="rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-950/30 dark:text-rose-200">API error: {error}</div>}

      {/* HEALTH */}
      <div className="grid gap-4 md:grid-cols-4">
        {[
          { k: 'database', label: t('قاعدة البيانات', 'Database'), v: data.health?.checks?.database },
          { k: 'redis', label: t('Redis', 'Redis'), v: data.health?.checks?.redis },
          { k: 'filesystem', label: t('نظام الملفات', 'Filesystem'), v: data.health?.checks?.filesystem },
          { k: 'queue', label: t('الطوابير', 'Queue'), v: data.health?.checks?.queue },
        ].map((c) => (
          <Card key={c.k}>
            <CardContent className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{c.label}</span>
              {statusBadge(c.v)}
            </CardContent>
          </Card>
        ))}
      </div>

      {/* HOST */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Cpu className="h-4 w-4" /> {t('المضيف', 'Host')}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {stat(t('CPU', 'CPU'), pct(host.cpu_percent), 'load ' + (host.loadavg_1 ?? '—'))}
          {stat(t('الذاكرة', 'Memory'), pct(host.mem_percent), mb(host.mem_used_mb) + ' / ' + mb(host.mem_total_mb))}
          {stat(t('القرص', 'Disk'), pct(host.disk_root_percent), mb(host.disk_root_used_mb) + ' / ' + mb(host.disk_root_total_mb))}
          {stat(t('Swap', 'Swap'), mb(host.swap_used_mb), `uptime ${Math.floor((host.uptime_seconds ?? 0) / 3600)}h`)}
        </CardContent>
      </Card>

      {/* DATABASE */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Database className="h-4 w-4" /> PostgreSQL</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {stat(t('الاتصالات', 'Connections'), `${db.connections_total ?? '—'} / ${db.connections_max ?? '—'}`)}
          {stat(t('الحجم', 'Size'), mb(db.database_size_bytes), pct(db.connections_percent) + ' conn')}
          {stat('Cache hit', pct(db.cache_hit_ratio))}
          {stat('Slow queries', db.slow_queries_running ?? 0, `locks ${db.locks ?? 0}`)}
        </CardContent>
      </Card>

      {/* REDIS */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><MemoryStick className="h-4 w-4" /> Redis</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {stat(t('الذاكرة', 'Memory'), pct(redis.memory_percent), mb(redis.used_memory_bytes) + ' / ' + mb(redis.maxmemory_bytes))}
          {stat('Clients', redis.connected_clients ?? 0, `${redis.ops_per_sec ?? 0} ops/s`)}
          {stat('Hit ratio', pct(redis.hit_ratio))}
          {stat('Evicted', redis.evicted_keys ?? 0, `blocked ${redis.blocked_clients ?? 0}`)}
        </CardContent>
      </Card>

      {/* QUEUE */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Boxes className="h-4 w-4" /> {t('الطوابير / Horizon', 'Queues / Horizon')}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <div className="rounded-2xl border bg-muted/30 p-4">
            <p className="text-xs text-muted-foreground">{t('Horizon', 'Horizon')}</p>
            <div className="mt-1">{statusBadge(queue.horizon)}</div>
          </div>
          {stat(t('معلّقة', 'Pending'), queue.total_pending ?? 0, JSON.stringify(queue.depth || {}))}
          {stat('Failed jobs', queue.failed_total ?? 0, `last 24h: ${queue.failed_last_24h ?? 0}`)}
          {stat(t('الاحتياطي', 'Backups'), `${backup.total_count ?? 0}`, `last ${backup.latest_age_hours ?? '—'}h`)}
        </CardContent>
      </Card>

      {/* SECURITY */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><ShieldAlert className="h-4 w-4" /> {t('الأمان', 'Security')}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {stat('Failed logins', security.failed_logins ?? 0)}
          {stat('Rate-limit hits', security.rate_limit_hits ?? 0)}
          {stat('403 denied', security.access_denied ?? 0)}
          {stat('HTTP 5xx', security.request_5xx ?? 0)}
        </CardContent>
      </Card>

      {/* APP REQUEST METRICS */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Activity className="h-4 w-4" /> {t('التطبيق', 'Application')}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {stat(t('الطلبات', 'Requests'), app.requests_total ?? 0, `${Math.round((app.requests_total ?? 0) / 300) * 5} req/5min`)}
          {stat('2xx/3xx/4xx/5xx', `${app.requests_2xx ?? 0}/${app.requests_3xx ?? 0}/${app.requests_4xx ?? 0}/${app.requests_5xx ?? 0}`)}
          {stat('Avg latency', app.requests_total ? `${Math.round((app.duration_ms_total ?? 0) / app.requests_total)}ms` : '—')}
          {stat('Max latency', `${app.max_duration_ms ?? 0}ms`)}
        </CardContent>
      </Card>

      {/* ALERTS */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><AlertTriangle className="h-4 w-4" /> {t('التنبيهات النشطة', 'Active Alerts')}</CardTitle>
        </CardHeader>
        <CardContent>
          {alerts.length === 0 ? (
            <p className="text-sm text-muted-foreground">لا توجد تنبيهات نشطة.</p>
          ) : (
            <div className="space-y-2">
              {alerts.map((a: any) => (
                <div key={a.id} className="flex flex-wrap items-center gap-3 rounded-xl border p-3">
                  {levelBadge(a.level)}
                  <span className="text-sm font-medium flex-1">{a.message}</span>
                  <span className="text-xs text-muted-foreground">x{a.occurrences} · {new Date(a.last_seen_at).toLocaleString()}</span>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}