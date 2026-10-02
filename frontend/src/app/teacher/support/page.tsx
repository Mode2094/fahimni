'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/empty-state';
import { TableSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { LifeBuoy, Loader2, Send, Plus, MessageSquare, Mail } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const STATUS_META: Record<string, [string, string]> = {
  open: ['مفتوحة', 'bg-teal-500/10 text-teal-700'],
  pending: ['قيد المراجعة', 'bg-amber-500/10 text-amber-600'],
  resolved: ['محلولة', 'bg-green-500/10 text-green-600'],
  closed: ['مغلقة', 'bg-gray-100 text-gray-500'],
};

const PR_META: Record<string, [string, string]> = {
  low: ['منخفضة', 'bg-gray-100 text-gray-500'],
  medium: ['متوسطة', 'bg-teal-500/10 text-teal-700'],
  high: ['عالية', 'bg-amber-500/10 text-amber-600'],
  urgent: ['عاجلة', 'bg-red-500/10 text-red-500'],
};

const stLabel = (s: string, locale: string) => (locale === 'ar' ? (STATUS_META[s]?.[0] || s) : s);
const chBadge = (ch: string) => ch === 'chat'
  ? ['bg-emerald-500/10 text-emerald-600', 'دردشة حية', 'Live chat']
  : ['bg-gray-500/10 text-gray-500', 'بريد إلكتروني', 'Email'];
const prLabel = (p: string, locale: string) => (locale === 'ar' ? (PR_META[p]?.[0] || p) : p);

export default function TeacherSupportPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { user } = useAuth();
  const t = (ar: string, en: string, _l?: string) => (_l ?? locale) === 'ar' ? ar : en;
  const [tickets, setTickets] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [detail, setDetail] = useState<any>(null);
  const [reply, setReply] = useState('');
  const [sending, setSending] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState({ subject: '', category: 'general', priority: 'medium', body: '' });
  const [creating, setCreating] = useState(false);
  const [supportLevel, setSupportLevel] = useState<string>('email');

  const headers = () => ({ 'Content-Type': 'application/json', 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  const load = () => {
    fetch(`${API}/tickets`, { headers: headers() })
      .then(r => r.json()).then(d => setTickets(d.tickets || [])).catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => {
    if (!check()) return;
    fetch(`${API}/teacher/plan`, { headers: headers() })
      .then(r => r.json())
      .then(d => { const lv = d.features?.support_level || 'email'; setSupportLevel(lv); })
      .catch(() => {});
  }, [check()]);

  const isDedicated = supportLevel === 'dedicated';

  const openDetail = async (id: string) => {
    try {
      const d = await fetch(`${API}/tickets/${id}`, { headers: headers() }).then(r => r.json());
      setDetail(d.ticket || null);
    } catch {}
  };

  const sendReply = async () => {
    if (!reply.trim() || !detail) return;
    setSending(true);
    try {
      const res = await fetch(`${API}/tickets/${detail.id}/messages`, { method: 'POST', headers: headers(), body: JSON.stringify({ body: reply }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setReply('');
      openDetail(detail.id);
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSending(false);
  };

  const create = async () => {
    if (!form.subject || !form.body) { toast.error(t('أدخل الموضوع والمحتوى', 'Enter subject and body')); return; }
    setCreating(true);
    try {
      const res = await fetch(`${API}/tickets`, { method: 'POST', headers: headers(), body: JSON.stringify(form) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم إرسال التذكرة', 'Ticket submitted'));
      setShowNew(false);
      setForm({ subject: '', category: 'general', priority: 'medium', body: '' });
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setCreating(false);
  };

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <LifeBuoy className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الدعم الفني', 'Support')}</h1>
            <p className="text-muted-foreground mt-1">{t('تذاكر الدعم — فريق المنصة يرد عليك هنا', 'Support tickets — our team replies here')}</p>
          </div>
        </div>
        <Button onClick={() => setShowNew(true)} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('تذكرة جديدة', 'New Ticket')}</Button>
      </div>

      {isDedicated ? (
        <div className="flex items-start gap-3 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
          <MessageSquare className="h-5 w-5 shrink-0 mt-0.5" />
          <div>
            <p className="font-bold">{t('خطتك توفر دعماً مخصصاً — دردشة حية', 'Your plan includes dedicated support — live chat')}</p>
            <p className="text-emerald-700/80 mt-0.5 text-xs">{t('تحدث مباشرة مع فريق المنصة داخل الموقع', 'Chat live with the platform team right here')}</p>
          </div>
        </div>
      ) : (
        <div className="flex items-start gap-3 p-4 rounded-2xl bg-gray-50 border border-gray-200 text-gray-600 text-sm">
          <Mail className="h-5 w-5 shrink-0 mt-0.5" />
          <div>
            <p className="font-bold">{t('خطتك توفر دعماً عبر البريد الإلكتروني', 'Your plan includes email support')}</p>
            <p className="text-gray-500 mt-0.5 text-xs">{t('أرسل تذكرتك وستصلك الردود على بريدك الإلكتروني — الدردشة الحية متاحة في خطط الدعم المخصص', 'Submit a ticket and replies arrive on your email — live chat is available on dedicated-support plans')}</p>
          </div>
        </div>
      )}

      {loading ? <TableSkeleton rows={4} cols={4} /> :
        tickets.length === 0 ? (
          <EmptyState icon={LifeBuoy} title={t('لا توجد تذاكر', 'No tickets')} description={t('أنشئ أول تذكرة دعم وسيرد عليك الفريق', 'Create your first support ticket and our team will reply')}
            action={<Button onClick={() => setShowNew(true)} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('تذكرة جديدة', 'New Ticket')}</Button>} />
        ) : (
          <Card><div className="divide-y">
            {tickets.map((tk) => (
              <button key={tk.id} onClick={() => openDetail(tk.id)} className="w-full text-start p-4 hover:bg-gray-50 flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 shrink-0"><MessageSquare className="h-5 w-5 text-teal-600" /></div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium">{tk.subject}</p>
                  <p className="text-[11px] text-gray-400 mt-0.5">{tk.category} · {tk.created_at?.split('T')[0]} · {tk.latest_message?.body?.slice(0, 60) || ''}</p>
                </div>
                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(STATUS_META[tk.status] || ['', ''])[1]}`}>{stLabel(tk.status, locale)}</span>
                <span className={`hidden sm:inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(PR_META[tk.priority] || ['', ''])[1]}`}>{prLabel(tk.priority, locale)}</span>
                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${chBadge(tk.channel)[0]}`}>{chBadge(tk.channel)[1]}</span>
              </button>
            ))}
          </div></Card>
        )}

      <Dialog open={!!detail} onOpenChange={(v) => { if (!v) setDetail(null); }}>
        <DialogContent className="max-w-2xl">
          {detail && (
            <>
              <DialogHeader>
                <div className="flex items-center gap-2 flex-wrap">
                  <DialogTitle>
                    {detail.subject}
                  </DialogTitle>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(STATUS_META[detail.status] || ['', ''])[1]}`}>{stLabel(detail.status, locale)}</span>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(PR_META[detail.priority] || ['', ''])[1]}`}>{prLabel(detail.priority, locale)}</span>
                </div>
                <p className="text-xs text-gray-500 mt-1">{t('تم الإنشاء', 'Created')}: {detail.created_at}</p>
              </DialogHeader>

              <div className="space-y-3 max-h-[360px] overflow-y-auto py-2">
                {(detail.messages || []).map((m: any) => {
                  const mine = m.user_id === user?.id;
                  return (
                    <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                      <div className={`max-w-[80%] rounded-2xl px-4 py-3 text-sm ${mine ? 'bg-teal-500 text-white rounded-ee-md' : 'bg-gray-100 text-gray-800 rounded-es-md'}`}>
                        <p className="text-[10px] opacity-70 mb-1">{m.user?.name || '—'} · {m.created_at}</p>
                        <p className="whitespace-pre-wrap">{m.body}</p>
                      </div>
                    </div>
                  );
                })}
              </div>

              {detail.channel === 'chat' ? (
                <div className="flex gap-2">
                  <Input value={reply} onChange={(e) => setReply(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && !e.shiftKey && sendReply()} placeholder={t('اكتب ردك...', 'Write your reply...')} className="h-11" />
                  <Button onClick={sendReply} disabled={sending || !reply.trim()} className="gap-2 shrink-0">
                    {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  </Button>
                </div>
              ) : (
                <div className="space-y-2">
                  <div className="p-3 rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-500 flex items-center gap-2">
                    <Mail className="h-4 w-4 shrink-0" />
                    {t('هذه التذكرة عبر البريد الإلكتروني — الردود تصل إلى بريدك. يمكنك إضافة معلومات إضافية هنا.', 'This ticket is email-based — replies arrive on your inbox. You can add more information here.')}
                  </div>
                  <div className="flex gap-2">
                    <Input value={reply} onChange={(e) => setReply(e.target.value)} placeholder={t('إضافة معلومات إضافية...', 'Add more information...')} className="h-11" />
                    <Button onClick={sendReply} disabled={sending || !reply.trim()} variant="outline" className="gap-2 shrink-0">
                      {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={showNew} onOpenChange={setShowNew}>
        <DialogContent>
          <DialogHeader><DialogTitle>{t('تذكرة دعم جديدة', 'New support ticket')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('الموضوع', 'Subject')} *</Label><Input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className="h-11" /></div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('التصنيف', 'Category')}</Label>
                <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="h-11">
                  {['general', 'billing', 'technical', 'cards', 'feature'].map(c => <option key={c} value={c}>{c}</option>)}
                </Select>
              </div>
              <div className="space-y-2"><Label>{t('الأولوية', 'Priority')}</Label>
                <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} className="h-11">
                  {['low', 'medium', 'high', 'urgent'].map(p => <option key={p} value={p}>{prLabel(p, locale)}</option>)}
                </Select>
              </div>
            </div>
            <div className="space-y-2"><Label>{t('المحتوى', 'Body')} *</Label><Textarea value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} rows={4} className="mt-1" /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowNew(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={create} disabled={creating} className="gap-2">{creating && <Loader2 className="h-4 w-4 animate-spin" />}{t('إرسال', 'Submit')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
