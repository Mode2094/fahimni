'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Alert } from '@/components/ui/alert';
import { confirmDialog } from '@/components/ui/confirm-dialog';
import {
  Globe, Loader2, Copy, CheckCircle2, AlertCircle, RefreshCw, Trash2, ExternalLink, Lock, Link2,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
const headers = () => ({ 'X-Tenant-Id': '', Accept: 'application/json' });

export type DomainTeacher = { id: string; name: string };

type Dns = {
  cname_host?: string;
  cname_value?: string;
  a_host?: string;
  a_value?: string;
  platform_ip?: string;
  txt_host?: string;
  txt_value?: string;
  steps?: string[];
  hostinger_steps?: string[];
  provider_note?: string;
};

type DomainStatus = {
  domain: string | null;
  verified: boolean;
  verified_at: string | null;
  status: 'none' | 'pending_dns' | 'failed' | 'active';
  status_reason: string | null;
  https_enabled: boolean;
  ssl_note?: string;
  site_url: string | null;
  www_url: string | null;
  platform_domain: string;
  subdomain: string;
  subdomain_url: string;
  dns_instructions: Dns;
};

type Props = {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  teacher: DomainTeacher;
  onDone?: () => void;
};

const STATUS_META: Record<string, { ar: string; en: string; cls: string }> = {
  none: { ar: 'غير مربوط', en: 'Not connected', cls: 'bg-gray-100 text-gray-600 border-0' },
  pending_dns: { ar: 'بانتظار إعداد DNS', en: 'Awaiting DNS', cls: 'bg-amber-100 text-amber-800 border-0' },
  failed: { ar: 'فشل التحقق', en: 'Verification failed', cls: 'bg-rose-100 text-rose-700 border-0' },
  active: { ar: 'نشط وآمن (HTTPS)', en: 'Active (HTTPS)', cls: 'bg-emerald-100 text-emerald-700 border-0' },
};

/**
 * Super-Admin management of a teacher's CUSTOM DOMAIN — connect, verify and
 * disconnect without signing in as the teacher, reusing the exact same
 * verification/TLS machinery the teacher's own domain page uses.
 *
 * No registrar (Hostinger/…) password is ever asked for or stored: the admin
 * edits the DNS records in the registrar's own panel using the instructions
 * shown here, which are derived from the real production settings.
 */
export default function TeacherDomainModal({ open, onOpenChange, teacher, onDone }: Props) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [data, setData] = useState<DomainStatus | null>(null);
  const [loading, setLoading] = useState(false);
  const [domainInput, setDomainInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    const ar = locale === 'ar';
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API}/admin/teachers/${teacher.id}/domain`, { headers: headers(), cache: 'no-store' });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { setError(d.message || (ar ? 'تعذّر جلب حالة الدومين' : 'Could not load the domain status')); return; }
      setData(d);
      setDomainInput(d.domain || '');
    } catch {
      setError(ar ? 'تعذّر الاتصال بالخادم' : 'Could not reach the server');
    } finally {
      setLoading(false);
    }
  }, [teacher.id, locale]);

  useEffect(() => {
    if (open) load();
  }, [open, load]);

  const connect = async () => {
    const domain = domainInput.trim().replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
    if (!domain) { setError(t('أدخل اسم الدومين', 'Enter a domain name')); return; }

    setBusy(true);
    setError('');
    try {
      const res = await fetch(`${API}/admin/teachers/${teacher.id}/domain`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ domain }),
      });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { setError(d.message || t('تعذّر ربط الدومين', 'Could not connect the domain')); return; }
      toast.success(t('تم ربط الدومين — انشر سجلات DNS ثم اضغط «فحص الربط».', 'Domain connected — publish the DNS records, then run the check.'));
      onDone?.();
      await load();
    } catch {
      setError(t('تعذّر الاتصال بالخادم', 'Could not reach the server'));
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await fetch(`${API}/admin/teachers/${teacher.id}/domain/verify`, { method: 'POST', headers: headers() });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { setError(d.message || t('تعذّر التحقق من الربط', 'Could not verify the domain')); return; }
      if (d.verified) toast.success(t('تم التحقق من الدومين — سيتفعّل HTTPS تلقائيًا.', 'Domain verified — HTTPS will activate automatically.'));
      else toast.error(t('لم يتم التحقق بعد. راجع سجلات DNS وحاول مجددًا.', 'Not verified yet. Check the DNS records and retry.'));
      await load();
    } catch {
      setError(t('تعذّر الاتصال بالخادم', 'Could not reach the server'));
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async () => {
    if (!(await confirmDialog({
      description: t(`إلغاء ربط «${data?.domain}»؟ سيعود المعلم إلى الرابط المجاني فقط.`, `Disconnect “${data?.domain}”? The teacher will fall back to the free link only.`),
    }))) return;

    setBusy(true);
    setError('');
    try {
      const res = await fetch(`${API}/admin/teachers/${teacher.id}/domain`, { method: 'DELETE', headers: headers() });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { setError(d.message || t('تعذّر إلغاء الربط', 'Could not disconnect the domain')); return; }
      toast.success(t('تم إلغاء ربط الدومين.', 'Domain disconnected.'));
      onDone?.();
      await load();
    } catch {
      setError(t('تعذّر الاتصال بالخادم', 'Could not reach the server'));
    } finally {
      setBusy(false);
    }
  };

  const copy = (value: string, label: string) => {
    navigator.clipboard?.writeText(value).then(
      () => toast.success(t(`تم نسخ ${label}`, `${label} copied`)),
      () => toast.error(t('تعذّر النسخ', 'Could not copy')),
    );
  };

  const dns = data?.dns_instructions;
  const statusMeta = data ? STATUS_META[data.status] : null;
  const connected = !!data?.domain;

  const RecordRow = ({ label, host, value }: { label: string; host?: string; value?: string }) => (
    <div className="flex items-center gap-2 rounded-xl border bg-white p-2.5 text-sm">
      <Badge className="border-0 bg-teal-50 text-teal-700 shrink-0">{label}</Badge>
      <span className="min-w-0 flex-1 font-mono text-xs text-gray-600 truncate" dir="ltr">
        {host ? `${host}  →  ` : ''}{value || '—'}
      </span>
      {value && (
        <Button size="icon" variant="ghost" className="h-7 w-7 shrink-0 rounded-full" aria-label={t('نسخ', 'Copy')}
          onClick={() => copy(value, label)}>
          <Copy className="h-3.5 w-3.5" />
        </Button>
      )}
    </div>
  );

  return (
    <Dialog open={open} onOpenChange={(v) => !busy && onOpenChange(v)}>
      <DialogContent className="max-w-3xl max-h-[88vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Globe className="h-5 w-5 text-teal-600" />
            {t('الدومين المخصص', 'Custom domain')}
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4" data-testid="teacher-domain-modal">
          <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-gray-50 p-3 text-sm">
            <span className="text-gray-400 text-xs">{t('المعلم', 'Teacher')}</span>
            <b>{teacher.name}</b>
            <span className="ms-auto flex items-center gap-2">
              {loading ? <Loader2 className="h-4 w-4 animate-spin text-gray-400" /> : statusMeta && (
                <Badge className={statusMeta.cls} data-testid="domain-status">{t(statusMeta.ar, statusMeta.en)}</Badge>
              )}
              <Button size="sm" variant="outline" className="gap-1.5 rounded-full" onClick={load} disabled={loading || busy} data-testid="domain-refresh">
                <RefreshCw className="h-3.5 w-3.5" /> {t('تحديث', 'Refresh')}
              </Button>
            </span>
          </div>

          {data?.status_reason && data.status !== 'active' && (
            <Alert variant="warning" icon={AlertCircle}>{data.status_reason}</Alert>
          )}

          {/* Connect / change the domain name */}
          <div className="space-y-2">
            <Label>{connected ? t('تغيير الدومين المربوط', 'Change the connected domain') : t('ربط دومين جديد', 'Connect a new domain')}</Label>
            <div className="flex flex-col sm:flex-row gap-2">
              <Input
                value={domainInput}
                onChange={(e) => setDomainInput(e.target.value)}
                placeholder="academy-jo.com"
                dir="ltr"
                className="h-11"
                data-testid="domain-input"
              />
              <Button className="gap-1.5 rounded-xl h-11 shrink-0" onClick={connect} disabled={busy} data-testid="domain-connect">
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Link2 className="h-4 w-4" />}
                {connected ? t('تحديث الربط', 'Update') : t('ربط الدومين', 'Connect')}
              </Button>
            </div>
            <p className="text-[11px] text-gray-400">
              {t('اكتب الدومين بدون www — يُخزَّن الجذر تلقائيًا ويعمل مع www أيضًا.', 'Enter the domain without www — the apex is stored and www works too.')}
            </p>
          </div>

          {/* DNS instructions — the exact records derived from production. */}
          {connected && dns && !data?.verified && (
            <div className="space-y-3">
              <div className="text-sm font-semibold flex items-center gap-1.5">
                <Globe className="h-4 w-4 text-teal-600" /> {t('سجلات DNS المطلوبة', 'Required DNS records')}
              </div>
              <RecordRow label={t('CNAME', 'CNAME')} host={dns.cname_host} value={dns.cname_value} />
              <RecordRow label={t('A (بديل)', 'A (fallback)')} host={dns.a_host} value={dns.a_value} />
              {dns.txt_value && <RecordRow label="TXT" host={dns.txt_host} value={dns.txt_value} />}

              {(dns.hostinger_steps?.length ?? 0) > 0 && (
                <details className="rounded-xl border bg-white p-3 text-sm">
                  <summary className="cursor-pointer font-medium text-teal-700">{t('خطوات Hostinger (hPanel)', 'Hostinger (hPanel) steps')}</summary>
                  <ol className="mt-2 space-y-1 text-[13px] text-gray-600 list-decimal ps-5">
                    {dns.hostinger_steps!.map((s, i) => <li key={i}>{s}</li>)}
                  </ol>
                </details>
              )}
              {(dns.steps?.length ?? 0) > 0 && (
                <ol className="space-y-1 text-[13px] text-gray-600 list-decimal ps-5">
                  {dns.steps!.map((s, i) => <li key={i}>{s}</li>)}
                </ol>
              )}
              {dns.provider_note && (
                <p className="text-[11px] text-gray-400 flex items-center gap-1"><Lock className="h-3 w-3" /> {dns.provider_note}</p>
              )}
            </div>
          )}

          {/* Active domain details */}
          {data?.status === 'active' && data.domain && (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 space-y-2 text-sm">
              <div className="flex flex-wrap items-center gap-2 font-semibold text-emerald-800">
                <CheckCircle2 className="h-4 w-4" /> {t('الدومين متصل ونشط', 'Domain connected & active')}
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                <a href={data.site_url || '#'} target="_blank" rel="noopener noreferrer" className="text-teal-700 hover:underline flex items-center gap-1" dir="ltr">
                  <ExternalLink className="h-3.5 w-3.5" />{data.site_url}
                </a>
                <span className="text-gray-500">{t('HTTPS', 'HTTPS')}: {data.https_enabled ? t('مفعّل (تلقائي)', 'Enabled (auto)') : t('قيد الإصدار', 'Issuing')}</span>
                {data.verified_at && <span className="text-gray-500">{t('تم التحقق', 'Verified')}: {new Date(data.verified_at).toLocaleDateString()}</span>}
              </div>
              {data.ssl_note && <p className="text-[11px] text-gray-500">{data.ssl_note}</p>}
            </div>
          )}

          {/* The free link always stays available (SEO/link policy). */}
          {data && (
            <div className="rounded-xl border bg-gray-50/60 p-3 text-[13px] text-gray-600">
              {t('الرابط المجاني للمعلم يبقى يعمل:', 'The teacher’s free link always keeps working:')}
              <a href={data.subdomain_url} target="_blank" rel="noopener noreferrer" className="ms-1 font-mono text-teal-700 hover:underline" dir="ltr">{data.subdomain}</a>
              <span className="block text-[11px] text-gray-400 mt-1">
                {connected
                  ? t('الدومين المخصص هو العنوان الأساسي للمشاركة؛ الرابط المجاني يبقى بديلاً آمنًا.', 'The custom domain is the primary share address; the free link stays as a safe fallback.')
                  : t('اربط دومينًا مخصصًا لإعطاء منصة المعلم عنوانًا احترافيًا.', 'Connect a custom domain to give the teacher’s platform a professional address.')}
              </span>
            </div>
          )}

          {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}
        </div>

        <DialogFooter className="mt-4">
          {connected && (
            <Button variant="outline" className="gap-1.5 text-rose-600 hover:text-rose-700" onClick={disconnect} disabled={busy} data-testid="domain-disconnect">
              <Trash2 className="h-3.5 w-3.5" /> {t('إلغاء الربط', 'Disconnect')}
            </Button>
          )}
          <Button variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>{t('إغلاق', 'Close')}</Button>
          {connected && !data?.verified && (
            <Button className="gap-1.5" onClick={verify} disabled={busy} data-testid="domain-verify">
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
              {t('فحص الربط', 'Check connection')}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
