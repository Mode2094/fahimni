'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import {
  CreditCard, Plus, Copy, Check, Loader2, Trash2, Upload, FileDown, Printer,
  QrCode, Layers, Users, CalendarDays, Ban, Package,
} from 'lucide-react';
import { toast } from 'sonner';
import QRCode from 'qrcode';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
const DAY_MS = 86400000;

export default function TeacherCardsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string, _l?: string) => (_l ?? locale) === 'ar' ? ar : en;
  const [courses, setCourses] = useState<any[]>([]);
  const [batches, setBatches] = useState<any[]>([]);
  const [selectedBatch, setSelectedBatch] = useState('');
  const [loading, setLoading] = useState(true);

  // generate form
  const [courseId, setCourseId] = useState('');
  const [count, setCount] = useState('5');
  const [expiresAt, setExpiresAt] = useState('');
  const [batchName, setBatchName] = useState('');
  const [generating, setGenerating] = useState(false);

  // import form
  const [file, setFile] = useState<File | null>(null);
  const [iCourseId, setICourseId] = useState('');
  const [iExpiresAt, setIExpiresAt] = useState('');
  const [iBatchName, setIBatchName] = useState('');
  const [importing, setImporting] = useState(false);

  const [copied, setCopied] = useState('');
  const [showQr, setShowQr] = useState<string | null>(null);
  const [qrData, setQrData] = useState<Record<string, string>>({});
  const [printing, setPrinting] = useState(false);
  const [cancellingId, setCancellingId] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = async () => {
    Promise.all([
      fetch(`${API}/cards/batches`, { headers: headers() }).then(r => r.json()).then(d => {
        setBatches(d.batches || []);
        setSelectedBatch(prev => prev || d.batches?.[0]?.batch_id || '');
      }).catch(() => {}),
      fetch(`${API}/courses`, { headers: headers() }).then(r => r.json()).then(d => setCourses(d.courses || [])).catch(() => {}),
    ]).finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!check()) return;
    load();
  }, [check()]);

  const selected = useMemo(() => batches.find((b: any) => b.batch_id === selectedBatch), [batches, selectedBatch]);
  const cards: any[] = selected?.cards || [];

  const generate = async () => {
    if (!courseId) { toast.error(t('اختر الكورس', 'Select course')); return; }
    setGenerating(true);
    try {
      const res = await fetch(`${API}/cards/generate`, {
        method: 'POST',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ course_id: courseId, count: Number(count), expires_at: expiresAt || null, batch_id: batchName || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t(`تم إنشاء ${d.cards?.length || count} بطاقة`, `Generated ${d.cards?.length || count} cards`, locale));
      setCourseId(''); setExpiresAt(''); setBatchName(''); setCount('5');
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setGenerating(false);
  };

  const importFile = async () => {
    if (!iCourseId) { toast.error(t('اختر الكورس', 'Select course')); return; }
    if (!file) { toast.error(t('اختر ملف Excel أو CSV', 'Choose an Excel or CSV file')); return; }
    setImporting(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('course_id', iCourseId);
      if (iExpiresAt) fd.append('expires_at', iExpiresAt);
      if (iBatchName) fd.append('batch_id', iBatchName);
      const res = await fetch(`${API}/cards/import`, { method: 'POST', headers: headers(), body: fd });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('فشل الاستيراد', 'Import failed')); return; }
      const s = d.summary || {};
      toast.success(
        t(
          `تم استيراد ${s.imported ?? 0} بطاقة — مكرر في الملف: ${s.duplicates_in_file ?? 0}، موجود مسبقاً: ${s.duplicates_in_db ?? 0}`,
          `Imported ${s.imported ?? 0} cards — file duplicates: ${s.duplicates_in_file ?? 0}, already exist: ${s.duplicates_in_db ?? 0}`,
          locale,
        ),
        { duration: 5000 },
      );
      setFile(null); setICourseId(''); setIExpiresAt(''); setIBatchName('');
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setImporting(false);
  };

  const downloadTemplate = async () => {
    try {
      const d = await fetch(`${API}/cards/import-template`, { headers: headers() }).then(r => r.json());
      const blob = new Blob([d.content || ''], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'cards-template.csv'; a.click();
      URL.revokeObjectURL(url);
    } catch { toast.error(t('تعذر تحميل القالب', 'Cannot download template')); }
  };

  const copyCode = (code: string) => { navigator.clipboard.writeText(code); setCopied(code); setTimeout(() => setCopied(''), 2000); };

  const ensureQr = async (code: string) => {
    if (qrData[code]) return;
    // The QR encodes the full activation URL: scanning it opens the card
    // page on the platform, which auto-fills the code, handles login, and
    // activates the card automatically.
    const url = `${window.location.origin}/card/${code}`;
    const dataUrl = await QRCode.toDataURL(url, { width: 180, margin: 1, errorCorrectionLevel: 'M' });
    setQrData(prev => ({ ...prev, [code]: dataUrl }));
  };

  const toggleQr = (code: string) => {
    if (showQr === code) { setShowQr(null); return; }
    ensureQr(code);
    setShowQr(code);
  };

  const cancelCard = async (id: string) => {
    if (!window.confirm(t('إلغاء هذه البطاقة؟', 'Cancel this card?'))) return;
    setCancellingId(id);
    try {
      const res = await fetch(`${API}/cards/${id}/cancel`, { method: 'POST', headers: headers() });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم الإلغاء', 'Cancelled'));
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setCancellingId('');
  };

  const printBatch = async () => {
    if (!selected) return;
    setPrinting(true);
    try {
      const items = await Promise.all(cards.map(async (c: any) => ({
        code: c.code,
        qr: await QRCode.toDataURL(c.code, { width: 200, margin: 1, errorCorrectionLevel: 'M' }),
        course: c.course?.title || '',
        status: c.status,
        used: c.used_at ? c.used_at.split('T')[0] : null,
        student: c.student?.name || c.student?.full_name || '',
      })));

      const win = window.open('', '_blank');
      if (!win) { toast.error(t('السماح بالنوافذ المنبثقة للطباعة', 'Allow popups to print')); return; }

      // All dynamic values are HTML-escaped — student names and course titles
      // are user-controlled and must never execute inside the print window.
      const esc = (v: any) => String(v ?? '').replace(/[&<>"']/g, (ch) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] as string
      ));

      const cardsHtml = items.map(item => `
        <div class="print-card ${item.status !== 'generated' ? 'dimmed' : ''}">
          <img src="${esc(item.qr)}" alt="QR" class="qr" />
          <div class="code">${esc(item.code)}</div>
          <div class="course">${esc(item.course) || '—'}</div>
          <div class="meta">
            ${item.status === 'generated' ? (selected.expires_at ? 'صالحة حتى ' + esc(selected.expires_at.split('T')[0]) : '') : ''}
            ${item.status === 'redeemed' ? 'مستخدمة — ' + esc(item.student) + (item.used ? ' (' + esc(item.used) + ')' : '') : ''}
          </div>
        </div>
      `).join('');

      win.document.write(`<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>بطاقات — ${esc(selected.course?.title || '')}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; padding: 24px; }
  .head { text-align: center; margin-bottom: 20px; }
  .head h2 { font-size: 20px; }
  .head p { color: #666; font-size: 13px; margin-top: 4px; }
  .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
  .print-card { border: 1.5px dashed #94a3b8; border-radius: 12px; padding: 14px; text-align: center; page-break-inside: avoid; }
  .print-card.dimmed { opacity: .45; }
  .qr { width: 120px; height: 120px; image-rendering: pixelated; }
  .code { font-family: 'Courier New', monospace; font-weight: 700; font-size: 15px; letter-spacing: 2px; margin: 6px 0 2px; direction: ltr; }
  .course { font-size: 12px; color: #334155; font-weight: 600; }
  .meta { font-size: 11px; color: #64748b; margin-top: 4px; }
  @media print { body { padding: 8px; } .no-print { display: none; } .print-card { border-color: #000; } }
</style></head><body>
  <div class="head no-print">
    <h2>بطاقات تفعيل — ${esc(selected.course?.title || '')}</h2>
    <p>دفعة ${esc(selected.batch_id)} · إجمالي ${esc(selected.total)} · مستخدمة ${esc(selected.redeemed)}</p>
    <button onclick="window.print()" style="margin-top:10px;padding:8px 24px;border-radius:8px;border:1px solid #333;background:#fff;cursor:pointer;font-size:14px">طباعة</button>
  </div>
  <div class="grid">${cardsHtml}</div>
</body></html>`);
      win.document.close();
    } catch { toast.error(t('تعذر تجهيز الطباعة', 'Cannot prepare print')); }
    setPrinting(false);
  };

  const statusBadge = (c: any) => {
    const styles: Record<string, string> = {
      generated: 'bg-teal-500/10 text-teal-700',
      redeemed: 'bg-green-500/10 text-green-600',
      expired: 'bg-gray-100 text-gray-500',
      cancelled: 'bg-red-500/10 text-red-500',
    };
    const labels: Record<string, string> = { generated: t('جاهزة', 'Ready'), redeemed: t('مستخدمة', 'Used'), expired: t('منتهية', 'Expired'), cancelled: t('ملغاة', 'Cancelled') };
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[c.status] || 'bg-gray-100'}`}>{labels[c.status] || c.status}</span>;
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <CreditCard className="h-6 w-6 text-teal-600" />
        </span>
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('بطاقات التسجيل', 'Registration Cards')}</h1>
          <p className="text-muted-foreground mt-1">{t('أنشئ البطاقات، استورد من Excel، اطبعها مع QR، وتتبع الاستخدام', 'Generate, import from Excel, print with QR, and track usage')}</p>
        </div>
      </div>

      {/* ─── Generate ─── */}
      <Card className="border-0 shadow-sm overflow-hidden"><CardContent className="p-6 space-y-4">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-500/10"><Plus className="h-4.5 w-4.5 text-teal-600" /></span>
          <div>
            <h3 className="font-bold text-sm">{t('إنشاء بطاقات', 'Generate cards')}</h3>
            <p className="text-[11px] text-muted-foreground">{t('بطاقات جاهزة برمز فريد لكل طالب', 'Ready-to-print cards with unique codes')}</p>
          </div>
        </div>
        <div className="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
          <div className="lg:col-span-2"><Label>{t('الكورس', 'Course')}</Label>
            <Select value={courseId} onChange={(e) => setCourseId(e.target.value)} className="h-11 mt-1 border-teal-100">
              <option value="">{t('— اختر —', '— Select —')}</option>
              {courses.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </Select>
          </div>
          <div><Label>{t('العدد', 'Count')}</Label><Input type="number" min={1} max={1000} value={count} onChange={(e) => setCount(e.target.value)} className="h-11 mt-1 border-teal-100" /></div>
          <div><Label>{t('صلاحية حتى', 'Valid until')}</Label><Input type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} className="h-11 mt-1 border-teal-100" /></div>
          <div><Label>{t('اسم الدفعة (اختياري)', 'Batch name (optional)')}</Label><Input value={batchName} onChange={(e) => setBatchName(e.target.value)} placeholder="دفعة 1" className="h-11 mt-1 border-teal-100" /></div>
        </div>
        <div className="flex justify-end">
          <Button onClick={generate} disabled={generating} className="gap-2 h-11 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
            {generating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />} {t('إنشاء', 'Generate')}
          </Button>
        </div>
      </CardContent></Card>

      {/* ─── Import ─── */}
      <Card className="border-0 shadow-sm overflow-hidden"><CardContent className="p-6 space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-500/10"><Upload className="h-4.5 w-4.5 text-violet-600" /></span>
            <div>
              <h3 className="font-bold text-sm">{t('استيراد من Excel / CSV', 'Import from Excel / CSV')}</h3>
              <p className="text-[11px] text-muted-foreground">{t('استورد أكوادك من ملف خارجي', 'Import your codes from an external file')}</p>
            </div>
          </div>
          <Button variant="outline" size="sm" className="gap-2 rounded-full" onClick={downloadTemplate}><FileDown className="h-4 w-4 text-teal-700" /> {t('تحميل القالب', 'Download template')}</Button>
        </div>
        <p className="text-xs text-muted-foreground">{t('العمود الأول في الملف يجب أن يحتوي على أكواد البطاقات (سطر لكل كود). الصيغة: .xlsx أو .csv', 'First column of the file must contain card codes (one per row). Supported: .xlsx or .csv')}</p>
        <div className="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
          <div className="lg:col-span-2"><Label>{t('الكورس', 'Course')}</Label>
            <Select value={iCourseId} onChange={(e) => setICourseId(e.target.value)} className="h-11 mt-1 border-teal-100">
              <option value="">{t('— اختر —', '— Select —')}</option>
              {courses.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </Select>
          </div>
          <div><Label>{t('صلاحية حتى', 'Valid until')}</Label><Input type="date" value={iExpiresAt} onChange={(e) => setIExpiresAt(e.target.value)} className="h-11 mt-1 border-teal-100" /></div>
          <div><Label>{t('اسم الدفعة (اختياري)', 'Batch name (optional)')}</Label><Input value={iBatchName} onChange={(e) => setIBatchName(e.target.value)} placeholder="دفعة 1" className="h-11 mt-1 border-teal-100" /></div>
          <div><Label>{t('ملف Excel', 'Excel file')}</Label>
            <Input type="file" accept=".xlsx,.csv,text/csv" onChange={(e) => setFile(e.target.files?.[0] || null)} className="h-11 mt-1 border-teal-100 file:h-10" />
          </div>
        </div>
        <div className="flex justify-end">
          <Button onClick={importFile} disabled={importing} className="gap-2 h-11 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #7c3aed, #8b5cf6)' }}>
            {importing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />} {t('استيراد', 'Import')}
          </Button>
        </div>
      </CardContent></Card>

      {/* ─── Batches ─── */}
      {loading ? <CardSkeleton count={3} /> : batches.length === 0 ? (
        <EmptyState icon={CreditCard} title={t('لا توجد بطاقات بعد', 'No cards yet')} description={t('أنشئ دفعة جديدة لبدء توليد بطاقات التسجيل', 'Create a new batch to start generating registration cards')} />
      ) : (
        <>
          <Card className="border-0 shadow-sm overflow-hidden"><CardContent className="p-4">
            <div className="flex items-center gap-2.5 mb-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500/10"><Layers className="h-4 w-4 text-teal-600" /></span>
              <h3 className="font-bold text-sm">{t('الدفعات', 'Batches')}</h3>
            </div>
            <div className="flex flex-wrap gap-2">
              {batches.map((b: any) => (
                <button key={b.batch_id} onClick={() => setSelectedBatch(b.batch_id)}
                  className={`px-4 py-2 rounded-full text-sm border transition-all ${selectedBatch === b.batch_id ? 'bg-teal-600 text-white border-teal-600 shadow-brand' : 'bg-white border-gray-200 hover:border-teal-300 hover:shadow-sm'}`}>
                  {b.course?.title || '—'} · <span className="font-mono text-xs">{b.batch_id.slice(0, 8)}</span>
                  <span className="ms-1 opacity-70">({b.total})</span>
                </button>
              ))}
            </div>
          </CardContent></Card>

          {selected && (
            <Card>
              <div className="p-4 border-b flex flex-wrap items-center gap-4">
                <div className="flex-1 min-w-[200px]">
                  <h3 className="font-semibold">{selected.course?.title || ''}</h3>
                  <p className="text-xs text-gray-400 font-mono mt-0.5">{selected.batch_id} · {t('تاريخ الإنشاء', 'Created')}: {selected.created_at?.split('T')[0] || ''}</p>
                  <div className="flex flex-wrap gap-2 mt-2">
                    <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium"><Package className="h-3 w-3" /> {t('إجمالي', 'Total')}: {selected.total}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-teal-500/10 px-2.5 py-0.5 text-xs font-medium text-teal-700"><CreditCard className="h-3 w-3" /> {t('جاهزة', 'Ready')}: {selected.generated}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-green-500/10 px-2.5 py-0.5 text-xs font-medium text-green-600"><Users className="h-3 w-3" /> {t('مستخدمة', 'Used')}: {selected.redeemed}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-0.5 text-xs font-medium text-red-500"><Ban className="h-3 w-3" /> {t('ملغاة', 'Cancelled')}: {selected.cancelled}</span>
                    {selected.expires_at && <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-medium text-amber-600"><CalendarDays className="h-3 w-3" /> {t('تنتهي', 'Expires')}: {selected.expires_at.split('T')[0]}</span>}
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button variant="outline" className="gap-2" onClick={printBatch} disabled={printing || cards.length === 0}>
                    {printing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Printer className="h-4 w-4" />} {t('طباعة الدفعة', 'Print batch')}
                  </Button>
                </div>
              </div>

              {cards.length === 0 ? (
                <EmptyState icon={CreditCard} title={t('لا توجد بطاقات في هذه الدفعة', 'No cards in this batch')} className="py-10" />
              ) : (
                <div className="divide-y max-h-[560px] overflow-y-auto">
                  {cards.map((c: any) => (
                    <div key={c.id} className="p-4">
                      <div className="flex items-center gap-3 flex-wrap">
                        <CreditCard className="h-5 w-5 text-teal-600 shrink-0" />
                        <div className="flex-1 min-w-[160px]">
                          <div className="flex items-center gap-2">
                            <code className="px-2 py-1 rounded bg-gray-100 text-sm font-mono">{c.code}</code>
                            {statusBadge(c)}
                          </div>
                          <p className="text-xs text-gray-500 mt-1">
                  {c.course?.title || '—'}
                  {c.expires_at && <span className="ms-2">· {t('تنتهي', 'Expires')}: {c.expires_at.split('T')[0]}</span>}
                  {c.status === 'redeemed' && (
                    <span className="ms-2 text-green-600">· {t('استخدمها', 'Used by')}: {c.student?.name || c.student?.full_name || c.student_id} {c.used_at ? `(${c.used_at.split('T')[0]})` : ''}</span>
                  )}
                          </p>
                        </div>
                        <div className="flex items-center gap-1">
                          <Button variant="ghost" size="sm" className="gap-1.5 text-xs" onClick={() => toggleQr(c.code)}>
                            <QrCode className={`h-4 w-4 ${showQr === c.code ? 'text-teal-600' : ''}`} /> {showQr === c.code ? t('إخفاء', 'Hide') : 'QR'}
                          </Button>
                          <Button variant="ghost" size="icon" onClick={() => copyCode(c.code)} title={t('نسخ الكود', 'Copy code')} aria-label={t('نسخ الكود', 'Copy code')}>
                            {copied === c.code ? <Check className="h-4 w-4 text-green-500" /> : <Copy className="h-4 w-4" />}
                          </Button>
                          {c.status !== 'redeemed' && (
                            <Button variant="ghost" size="icon" className="text-red-400 hover:text-red-600" onClick={() => cancelCard(c.id)} disabled={cancellingId === c.id} title={t('إلغاء', 'Cancel')} aria-label={t('إلغاء', 'Cancel')}>
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          )}
                        </div>
                      </div>
                      {showQr === c.code && (
                        <div className="mt-3 flex justify-center">
                          {qrData[c.code] ? (
                            <div className="flex flex-col items-center gap-1 p-4 border border-gray-200 rounded-xl bg-white">
                              <img src={qrData[c.code]} alt="QR" className="w-32 h-32" />
                              <code className="text-xs font-mono text-gray-600">{c.code}</code>
                              <p className="text-[10px] text-gray-400">{t('امسح الرمز ليظهر كود التفعيل', 'Scan to read the activation code')}</p>
                            </div>
                          ) : <Loader2 className="h-6 w-6 animate-spin text-teal-600" />}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </Card>
          )}
        </>
      )}
    </div>
  );
}
