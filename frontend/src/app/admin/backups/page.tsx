'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Progress } from '@/components/ui/progress';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import {
  Database, Plus, Download, Trash2, ShieldCheck, Clock, AlertCircle, HardDrive, CheckCircle2,
  Loader2, Settings2, CalendarClock, RefreshCw,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const STATUS_META: Record<string, { ar: string; en: string; cls: string }> = {
  queued: { ar: 'في الانتظار', en: 'Queued', cls: 'bg-slate-100 text-slate-600' },
  running: { ar: 'جارٍ الإنشاء', en: 'Running', cls: 'bg-blue-50 text-blue-600' },
  verifying: { ar: 'جارٍ التحقق', en: 'Verifying', cls: 'bg-amber-50 text-amber-600' },
  completed: { ar: 'مكتملة', en: 'Completed', cls: 'bg-emerald-50 text-emerald-600' },
  failed: { ar: 'فشلت', en: 'Failed', cls: 'bg-red-50 text-red-600' },
  deleted: { ar: 'محذوفة', en: 'Deleted', cls: 'bg-gray-100 text-gray-400' },
};

const STAGE_LABELS: Record<string, [string, string]> = {
  queued: ['في الانتظار', 'Queued'],
  starting: ['البدء', 'Starting'],
  database_dump: ['نسخ قاعدة البيانات', 'Database dump'],
  files_archive: ['نسخ الملفات', 'Files archive'],
  configuration: ['تشفير الإعدادات', 'Encrypting config'],
  manifest: ['إنشاء الوصف', 'Building manifest'],
  checksums: ['حساب المجاميع', 'Checksums'],
  verification: ['التحقق من النسخة', 'Verifying'],
  queued_for_verification: ['التحقق المطلوب', 'Verify queued'],
  completed: ['مكتملة', 'Completed'],
  failed: ['فشلت', 'Failed'],
  job_failed: ['فشلت الوظيفة', 'Job failed'],
  verification_failed: ['فشل التحقق', 'Verification failed'],
};

export default function AdminBackupsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [backups, setBackups] = useState<any[]>([]);
  const [latest, setLatest] = useState<any>(null);
  const [settings, setSettings] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [verifyingId, setVerifyingId] = useState('');
  const [deleting, setDeleting] = useState('');
  const [error, setError] = useState('');
  const [savingSettings, setSavingSettings] = useState(false);
  const [showSettings, setShowSettings] = useState(false);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${API}/admin/backups`, { headers: headers() });
      const d = await res.json();
      if (!res.ok) throw new Error();
      setBackups(d.backups || []);
      setLatest(d.latest || null);
      setError('');
    } catch {
      setError(t('تعذر تحميل النسخ الاحتياطية', 'Failed to load backups'));
    } finally {
      setLoading(false);
    }
  }, []);

  const loadSettings = useCallback(async () => {
    try {
      const res = await fetch(`${API}/admin/backups/settings`, { headers: headers() });
      const d = await res.json();
      if (res.ok) setSettings(d.settings);
    } catch { /* */ }
  }, []);

  // Live polling while any backup is non-terminal.
  const hasActive = backups.some((b) => ['queued', 'running', 'verifying'].includes(b.status));

  useEffect(() => {
    if (!check()) return;
    load();
    loadSettings();
  }, [check(), load, loadSettings]);

  useEffect(() => {
    if (!hasActive) return;
    const id = setInterval(() => load(), 3000);
    return () => clearInterval(id);
  }, [hasActive, load]);

  const create = async () => {
    setCreating(true);
    try {
      const res = await fetch(`${API}/admin/backups`, { method: 'POST', headers: headers() });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('فشل إنشاء النسخة', 'Failed to create backup')); return; }
      toast.success(t('بدأ إنشاء النسخة الاحتياطية في الخلفية', 'Backup creation started in the background'));
      load();
    } catch {
      toast.error(t('تعذر بدء النسخة الاحتياطية', 'Could not start backup'));
    }
    setCreating(false);
  };

  const verify = async (b: any) => {
    setVerifyingId(b.id);
    try {
      const res = await fetch(`${API}/admin/backups/${b.id}/verify`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('بدأ التحقق من النسخة', 'Verification started'));
      load();
    } catch {
      toast.error(t('تعذر بدء التحقق', 'Could not start verification'));
    }
    setVerifyingId('');
  };

  const remove = async (b: any) => {
    if (!window.confirm(t(`حذف النسخة الاحتياطية «${b.backup_id}»؟`, `Delete backup "${b.backup_id}"?`))) return;
    setDeleting(b.id);
    try {
      const res = await fetch(`${API}/admin/backups/${b.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم حذف النسخة', 'Backup deleted'));
      load();
    } catch {
      toast.error(t('فشل الحذف', 'Delete failed'));
    }
    setDeleting('');
  };

  const download = (b: any, artifact?: string) => {
    const url = artifact
      ? `${API}/admin/backups/${b.id}/download/${artifact}`
      : `${API}/admin/backups/${b.id}/download`;
    const a = document.createElement('a');
    a.href = url;
    a.setAttribute('download', '');
    document.body.appendChild(a);
    a.click();
    a.remove();
  };

  const saveSettings = async (patch: any) => {
    setSavingSettings(true);
    try {
      const res = await fetch(`${API}/admin/backups/settings`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify(patch),
      });
      const d = await res.json();
      if (!res.ok) throw new Error();
      setSettings(d.settings);
      toast.success(t('تم حفظ الإعدادات', 'Settings saved'));
    } catch {
      toast.error(t('فشل حفظ الإعدادات', 'Could not save settings'));
    }
    setSavingSettings(false);
  };

  const statusBadge = (s: string) => {
    const m = STATUS_META[s] || STATUS_META.queued;
    return <Badge variant="outline" className={`text-xs ${m.cls}`}>{locale === 'ar' ? m.ar : m.en}</Badge>;
  };

  const stageLabel = (s: string) => {
    const l = STAGE_LABELS[s] || [s, s];
    return locale === 'ar' ? l[0] : l[1];
  };

  const fmtDate = (iso?: string) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-GB', {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  };

  const shortChecksum = (c?: string) => (c ? c.slice(0, 16) + '…' : '—');

  const activeBackup = backups.find((b) => ['queued', 'running', 'verifying'].includes(b.status)) || null;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#05966912' }}>
            <Database className="h-6 w-6" style={{ color: '#059669' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('النسخ الاحتياطي', 'Backups')}</h1>
            <p className="text-muted-foreground mt-1">{t('نسخة احتياطية كاملة آمنة: قاعدة البيانات + الملفات + الإعدادات المشفرة', 'Full secure backup: database + files + encrypted configuration')}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" className="gap-2 rounded-full" onClick={() => { setShowSettings(s => !s); }}>
            <Settings2 className="h-4 w-4" />{t('الإعدادات', 'Settings')}
          </Button>
          <Button onClick={create} disabled={creating || hasActive} className="gap-2 rounded-full">
            {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
            {t('إنشاء نسخة احتياطية كاملة', 'Create full backup')}
          </Button>
        </div>
      </div>

      {/* Active pipeline progress */}
      {activeBackup && (
        <Card className="border-blue-100 bg-blue-50/40">
          <CardContent className="p-5 space-y-3">
            <div className="flex items-center justify-between flex-wrap gap-2">
              <div className="flex items-center gap-2 font-semibold text-sm text-blue-800">
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('جارٍ إنشاء نسخة احتياطية…', 'Creating backup…')} <span className="font-mono" dir="ltr">{activeBackup.backup_id}</span>
              </div>
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <span>{stageLabel(activeBackup.stage)}</span>
                <span className="font-semibold">{activeBackup.progress ?? 0}%</span>
              </div>
            </div>
            <Progress value={activeBackup.progress ?? 0} barClassName="bg-blue-500" />
            <p className="text-[12px] text-slate-500">{t('يمكنك ترك الصفحة — يكتمل الإنشاء في الخلفية ويظهر تلقائياً عند الانتهاء.', 'You can leave the page — creation finishes in the background and appears automatically.')}</p>
          </CardContent>
        </Card>
      )}

      {/* Latest backup summary */}
      {latest && !activeBackup && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-5 flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-4">
              <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50"><ShieldCheck className="h-5 w-5 text-emerald-600" /></span>
              <div>
                <p className="text-sm font-semibold">{t('آخر نسخة احتياطية', 'Latest backup')}</p>
                <p className="text-xs text-muted-foreground mt-0.5 flex items-center gap-1.5"><Clock className="h-3 w-3" />{fmtDate(latest.created_at)} · {latest.size} · {latest.tables_count} {t('جدول', 'tables')}</p>
              </div>
            </div>
            <div className="flex items-center gap-2 flex-wrap">
              {statusBadge(latest.status)}
              <span className="text-xs text-muted-foreground">{t('عدد الملفات', 'Files')}: {latest.file_count ?? 0}</span>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Settings panel */}
      {showSettings && settings && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-5 space-y-4">
            <div className="flex items-center gap-2 font-semibold text-sm">
              <CalendarClock className="h-4 w-4 text-violet-600" />{t('النسخ الاحتياطي التلقائي', 'Automatic backup')}
            </div>

            <div className="grid sm:grid-cols-2 gap-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <label className="text-sm font-medium">{t('تفعيل النسخ التلقائي', 'Enable automatic backups')}</label>
                  <Switch
                    checked={!!settings.automatic_enabled}
                    onCheckedChange={(v) => saveSettings({ automatic_enabled: v })}
                    disabled={savingSettings}
                    label="automatic backups"
                  />
                </div>
                <p className="text-[12px] text-muted-foreground">{t('عند التفعيل يُنشأ نسخة تلقائياً حسب الجدول أدناه. النسخ التلقائي لا يعمل إلا بعد التفعيل من هنا.', 'When enabled, a backup runs automatically per the schedule below. Automatic backups never run until enabled here.')}</p>
              </div>

              <div className="space-y-2">
                <div className="grid grid-cols-3 gap-2">
                  <div className="space-y-1">
                    <label className="text-xs font-medium text-muted-foreground">{t('التكرار', 'Frequency')}</label>
                    <Select
                      value={settings.frequency}
                      disabled={savingSettings}
                      onChange={(e) => saveSettings({ frequency: e.target.value })}
                    >
                      <option value="daily">{t('يومي', 'Daily')}</option>
                      <option value="weekly">{t('أسبوعي', 'Weekly')}</option>
                    </Select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-xs font-medium text-muted-foreground">{t('الوقت', 'Time')}</label>
                    <Input
                      type="time"
                      value={settings.time}
                      disabled={savingSettings}
                      onChange={(e) => saveSettings({ time: e.target.value })}
                      className="h-10"
                    />
                  </div>
                  {settings.frequency === 'weekly' && (
                    <div className="space-y-1">
                      <label className="text-xs font-medium text-muted-foreground">{t('اليوم', 'Day')}</label>
                      <Select
                        value={String(settings.weekly_day)}
                        disabled={savingSettings}
                        onChange={(e) => saveSettings({ weekly_day: Number(e.target.value) })}
                      >
                        {[
                          [0, 'الأحد', 'Sun'], [1, 'الاثنين', 'Mon'], [2, 'الثلاثاء', 'Tue'], [3, 'الأربعاء', 'Wed'],
                          [4, 'الخميس', 'Thu'], [5, 'الجمعة', 'Fri'], [6, 'السبت', 'Sat'],
                        ].map(([v, ar, en]) => (
                          <option key={v} value={v}>{locale === 'ar' ? ar : en}</option>
                        ))}
                      </Select>
                    </div>
                  )}
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground">{t('عدد النسخ المحتفظ بها', 'Retention count')}</label>
                  <Input
                    type="number"
                    min={1}
                    max={365}
                    value={settings.retention_count}
                    disabled={savingSettings}
                    onChange={(e) => {
                      const v = Math.max(1, Math.min(365, Number(e.target.value) || 1));
                      saveSettings({ retention_count: v });
                    }}
                    className="h-10"
                  />
                </div>
              </div>
            </div>

            <div className="flex items-center gap-2 text-[12px] text-muted-foreground rounded-xl bg-muted/40 p-3">
              <HardDrive className="h-3.5 w-3.5 shrink-0" />
              <span dir="ltr">{settings.storage?.disk || 'backup'}</span>
              <span>·</span>
              <span>
                {settings.storage?.free_bytes != null
                  ? t('مساحة متاحة: ', 'Free space: ') + (settings.storage.free_bytes / 1073741824).toFixed(1) + ' GB'
                  : t('تخزين خاص (غير عام)', 'Private storage (not public)')}
              </span>
              <span>·</span>
              <span>{settings.storage?.reachable ? t('قابل للكتابة', 'Writable') : t('غير قابل للكتابة', 'Not writable')}</span>
            </div>
          </CardContent>
        </Card>
      )}

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {/* Table */}
      {loading ? (
        <Card><CardContent className="p-6"><TableSkeleton cols={6} rows={4} /></CardContent></Card>
      ) : backups.length === 0 ? (
        <EmptyState
          icon={Database}
          title={t('لا توجد نسخ بعد', 'No backups yet')}
          description={t('أنشئ نسخة احتياطية كاملة من قاعدة البيانات والملفات', 'Create a full database + files backup')}
          action={<Button onClick={create} disabled={creating || hasActive} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" />{t('أنشئ أول نسخة', 'Create first backup')}</Button>}
        />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('التاريخ', 'Date')}</TableHead>
              <TableHead>{t('النوع', 'Type')}</TableHead>
              <TableHead>{t('الحجم', 'Size')}</TableHead>
              <TableHead>{t('الحالة', 'Status')}</TableHead>
              <TableHead>{t('الإصدار', 'Version')}</TableHead>
              <TableHead>{t('Checksum', 'Checksum')}</TableHead>
              <TableHead className="text-end">{t('الإجراءات', 'Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {backups.map((b) => (
              <TableRow key={b.id}>
                <TableCell>
                  <div className="flex flex-col">
                    <span className="font-medium text-[13px]">{fmtDate(b.created_at)}</span>
                    <span className="font-mono text-[11px] text-muted-foreground" dir="ltr">{b.backup_id}</span>
                  </div>
                </TableCell>
                <TableCell>
                  <Badge variant="outline" className="text-[11px] bg-violet-50 text-violet-600">{t('كاملة', 'Full')}</Badge>
                </TableCell>
                <TableCell className="text-[13px]">
                  <span className="font-medium">{b.size}</span>
                  <span className="text-muted-foreground text-[11px] block">· {b.file_count ?? 0} {t('ملف', 'files')} · {b.tables_count ?? 0} {t('جدول', 'tables')}</span>
                </TableCell>
                <TableCell>
                  <div className="flex flex-col gap-1">
                    {statusBadge(b.status)}
                    {(b.status === 'running' || b.status === 'verifying') && (
                      <div className="w-24"><Progress value={b.progress ?? 0} barClassName="bg-blue-500" /></div>
                    )}
                    {b.status === 'failed' && b.error_message && (
                      <span className="text-[11px] text-red-500 max-w-[180px] truncate" title={b.error_message}>{b.error_message}</span>
                    )}
                  </div>
                </TableCell>
                <TableCell className="text-[12px] text-muted-foreground">
                  {b.laravel_version || b.app_version || '—'}
                </TableCell>
                <TableCell>
                  <span className="font-mono text-[11px] text-slate-500" dir="ltr">{shortChecksum(b.checksum)}</span>
                  {b.verified_at && (
                    <span className="flex items-center gap-1 text-[10px] text-emerald-600 mt-0.5"><CheckCircle2 className="h-3 w-3" />{t('متحقق منها', 'Verified')}</span>
                  )}
                </TableCell>
                <TableCell className="text-end">
                  <div className="flex items-center justify-end gap-1">
                    {b.status === 'completed' && (
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs" onClick={() => download(b)}>
                        <Download className="h-3.5 w-3.5" />{t('تحميل', 'Download')}
                      </Button>
                    )}
                    {['completed', 'failed'].includes(b.status) && (
                      <Button
                        size="sm"
                        variant="ghost"
                        className="gap-1.5 rounded-full text-xs"
                        onClick={() => verify(b)}
                        disabled={verifyingId === b.id || hasActive}
                      >
                        {verifyingId === b.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                        {t('تحقق', 'Verify')}
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" className="gap-1.5 rounded-full text-xs text-red-500" onClick={() => remove(b)} disabled={deleting === b.id || hasActive}>
                      {deleting === b.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                      {t('حذف', 'Delete')}
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {/* How it works */}
      <div className="rounded-2xl border border-teal-100 bg-teal-50/50 p-5 space-y-3">
        <div className="flex items-center gap-2 text-teal-800 font-bold text-sm">
          <ShieldCheck className="h-4 w-4" /> {t('كيف يعمل النسخ الاحتياطي؟', 'How does backup work?')}
        </div>
        <ul className="text-[13px] text-slate-600 space-y-1.5 leading-relaxed">
          <li>• {t('قاعدة البيانات: لقطة pg_dump كاملة لكل الجداول (اكتشاف تلقائي) — قابلة للاستعادة عبر pg_restore.', 'Database: full pg_dump of every table (auto-discovered) — restorable with pg_restore.')}</li>
          <li>• {t('الملفات: أرشيف tar.gz لكل الملفات المحلية (المرفقات، الصور، PDF). لا تُنزَّل فيديوهات Bunny — معرفاتها محفوظة في قاعدة البيانات.', 'Files: tar.gz archive of all local files (attachments, images, PDFs). Bunny videos are not downloaded — their references are kept in the database.')}</li>
          <li>• {t('الإعدادات: ملف .env والمفاتيح مُشفَّرة AES-256-GCM (configuration.enc) — لا تُعرض أبداً.', 'Configuration: .env and keys are AES-256-GCM encrypted (configuration.enc) — never exposed.')}</li>
          <li>• {t('التحقق: كل ملف يُحسب له SHA-256 وتُفحص النسخة (pg_restore --list + tar --test) قبل اعتمادها.', 'Verification: every file is SHA-256 checksummed and the package is validated before it is marked complete.')}</li>
          <li>• {t('الأمان: هذه الصفحة للمدير العام فقط، والتنزيل عبر روابط موقعة لا تكشف المسار الحقيقي.', 'Security: super admin only; downloads use signed links and never expose real paths.')}</li>
        </ul>
        <div className="rounded-xl bg-white/70 border border-teal-100 p-3 text-[12px] text-slate-500">
          {t('للاستعادة: حمّل النسخة وسلّمها لفريق التقنية — يُستعاد الـ dump عبر pg_restore وتُفك ضغط الملفات، وتُفك شفرة الإعدادات عبر scripts/decrypt-backup.php.', 'To restore: download the backup and hand it to your technical team — restore the dump with pg_restore, unpack the files, and decrypt the configuration with scripts/decrypt-backup.php.')}
        </div>
      </div>
    </div>
  );
}