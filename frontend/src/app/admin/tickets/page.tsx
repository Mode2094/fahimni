'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { LifeBuoy, Loader2, Search, Send, Plus, RefreshCcw, User, Building2, Mail, MessageSquare, AlertCircle } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const PRIORITY_META: Record<string, [string, string]> = {
  low: ['منخفضة', 'bg-gray-100 text-gray-500'],
  medium: ['متوسطة', 'bg-teal-500/10 text-teal-700'],
  high: ['عالية', 'bg-amber-500/10 text-amber-600'],
  urgent: ['عاجلة', 'bg-red-500/10 text-red-500'],
};

const STATUS_META: Record<string, [string, string]> = {
  open: ['مفتوحة', 'bg-teal-500/10 text-teal-700'],
  pending: ['قيد المراجعة', 'bg-amber-500/10 text-amber-600'],
  resolved: ['محلولة', 'bg-green-500/10 text-green-600'],
  closed: ['مغلقة', 'bg-gray-100 text-gray-500'],
};

const prLabel = (p: string, locale: string) => (locale === 'ar' ? (PRIORITY_META[p]?.[0] || p) : p);
const chBadge = (ch: string) => ch === 'chat'
  ? ['bg-emerald-500/10 text-emerald-600', 'دردشة حية', 'Live chat']
  : ['bg-gray-500/10 text-gray-500', 'بريد إلكتروني', 'Email'];
const stLabel = (s: string, locale: string) => {
  if (locale === 'ar') return STATUS_META[s]?.[0] || s;
  return { open: 'Open', pending: 'Pending', resolved: 'Resolved', closed: 'Closed' }[s] || s;
};

export default function AdminTicketsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { user } = useAuth();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [tickets, setTickets] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [channelFilter, setChannelFilter] = useState('');
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<any>(null);
  const [reply, setReply] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = async () => {
    const q = new URLSearchParams();
    if (statusFilter) q.set('status', statusFilter);
    if (channelFilter) q.set('channel', channelFilter);
    if (search) q.set('search', search);
    try {
      const d = await fetch(`${API}/admin/tickets?${q}`, { headers: headers() }).then(r => r.json());
      setTickets(d.tickets || []);
      setStats(d.stats || null);
      setError('');
    } catch { setError(t('تعذر تحميل التذاكر', 'Failed to load tickets')); }
    setLoading(false);
  };

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => { if (check()) load(); }, [statusFilter, search]);

  const openDetail = async (id: string) => {
    try {
      const d = await fetch(`${API}/admin/tickets/${id}`, { headers: headers() }).then(r => r.json());
      setDetail(d.ticket || null);
    } catch {}
  };

  const sendReply = async () => {
    if (!reply.trim()) return;
    setSending(true);
    try {
      const res = await fetch(`${API}/admin/tickets/${detail.id}/messages`, { method: 'POST', headers: headers(), body: JSON.stringify({ body: reply }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setReply('');
      openDetail(detail.id);
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSending(false);
  };

  const setStatus = async (status: string) => {
    try {
      const res = await fetch(`${API}/admin/tickets/${detail.id}/status`, { method: 'PUT', headers: headers(), body: JSON.stringify({ status }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم تحديث الحالة', 'Status updated'));
      openDetail(detail.id);
      load();
    } catch {}
  };


  const statCards = [
    { label: t('الكل', 'Total'), value: stats?.total ?? 0 },
    { label: t('مفتوحة', 'Open'), value: stats?.open ?? 0, color: 'text-teal-700' },
    { label: t('قيد المراجعة', 'Pending'), value: stats?.pending ?? 0, color: 'text-amber-600' },
    { label: t('محلولة', 'Resolved'), value: stats?.resolved ?? 0, color: 'text-green-600' },
    { label: t('عاجلة', 'Urgent'), value: stats?.urgent ?? 0, color: 'text-red-500' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <LifeBuoy className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الدعم الفني — نظام التذاكر', 'Support — Ticket System')}</h1>
            <p className="text-muted-foreground mt-1">{t('تذاكر الدعم من المعلمين والطلاب', 'Support tickets from teachers and students')}</p>
          </div></div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        {statCards.map((s, i) => (
          <Card key={i}><CardContent className="p-4">
            <p className="text-sm text-gray-500">{s.label}</p>
            <p className={`text-xl font-bold mt-1 ${s.color || ''}`}>{s.value}</p>
          </CardContent></Card>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative flex-1 min-w-[220px] max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث بالموضوع أو المرسل...', 'Search subject or sender...')} value={search} onChange={(e) => setSearch(e.target.value)} className="ps-9 h-10" />
        </div>
        <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-auto" aria-label={t('حالة التذكرة', 'Ticket status')}>
          <option value="">{t('كل الحالات', 'All statuses')}</option>
          {['open', 'pending', 'resolved', 'closed'].map(s => <option key={s} value={s}>{stLabel(s, locale)}</option>)}
        </Select>
        <Select value={channelFilter} onChange={(e) => setChannelFilter(e.target.value)} className="w-auto" aria-label={t('قناة التذكرة', 'Ticket channel')}>
          <option value="">{t('كل القنوات', 'All channels')}</option>
          <option value="chat">{t('دردشة حية', 'Live chat')}</option>
          <option value="email">{t('بريد إلكتروني', 'Email')}</option>
        </Select>
        <Button variant="outline" size="sm" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={4} rows={6} /></CardContent></Card> :
        tickets.length === 0 ? (
          <EmptyState icon={LifeBuoy} title={t('لا توجد تذاكر', 'No tickets')} description={t('لم يصل أي طلب دعم بعد', 'No support requests yet')} />
        ) : (
          <Card><div className="divide-y">
            {tickets.map((tk) => {
              const p = PRIORITY_META[tk.priority] || ['', ''];
              const st = STATUS_META[tk.status] || ['', ''];
              return (
                <button key={tk.id} onClick={() => openDetail(tk.id)} className="w-full text-start p-4 hover:bg-gray-50 flex flex-wrap items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 shrink-0"><LifeBuoy className="h-5 w-5 text-teal-600" /></div>
                  <div className="flex-1 min-w-[200px]">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="font-medium">{tk.subject}</p>
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${p[1]}`}>{prLabel(tk.priority, locale)}</span>
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${st[1]}`}>{stLabel(tk.status, locale)}</span>
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${chBadge(tk.channel)[0]}`}>{chBadge(tk.channel)[1]}</span>
                    </div>
                    <p className="text-xs text-gray-500 mt-1 flex items-center gap-1"><User className="h-3 w-3" /> {tk.user?.name || '—'} <span className="text-gray-300">·</span> <Building2 className="h-3 w-3" /> {tk.tenant?.name || t('منصة', 'Platform')}</p>
                    <p className="text-[11px] text-gray-400 mt-0.5">{tk.latest_message?.body?.slice(0, 80) || ''}</p>
                  </div>
                  <div className="text-xs text-gray-400 text-end">
                    <p>{tk.created_at?.split('T')[0]}</p>
                    <p className="mt-1">{tk.created_at?.split('T')[1]?.slice(0, 5)}</p>
                  </div>
                </button>
              );
            })}
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
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(PRIORITY_META[detail.priority] || ['', ''])[1]}`}>{prLabel(detail.priority, locale)}</span>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${(STATUS_META[detail.status] || ['', ''])[1]}`}>{stLabel(detail.status, locale)}</span>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${chBadge(detail.channel)[0]}`}>{chBadge(detail.channel)[1]}</span>
                </div>
                <p className="text-xs text-gray-500 mt-1">
                  {detail.user?.name || '—'} · {detail.tenant?.name || t('منصة', 'Platform')} · {detail.category} · {detail.created_at?.split('T')[0]}
                </p>
              </DialogHeader>

              <div className="space-y-3 max-h-[320px] overflow-y-auto py-2">
                {(detail.messages || []).map((m: any) => {
                  const mine = m.user_id === user?.id || m.user?.is_super_admin;
                  return (
                    <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                      <div className={`max-w-[80%] rounded-2xl px-4 py-3 text-sm ${mine ? 'bg-teal-500 text-white rounded-br-md' : 'bg-gray-100 text-gray-800 rounded-bl-md'}`}>
                        <p className="text-[10px] opacity-70 mb-1">{m.user?.name || '—'} · {m.created_at}</p>
                        <p className="whitespace-pre-wrap">{m.body}</p>
                      </div>
                    </div>
                  );
                })}
              </div>

              {detail.channel === 'email' && (
                <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-2">
                  <Mail className="h-4 w-4 shrink-0" />
                  {t('خطة المعلم توفر دعماً بالبريد فقط — الرد سيُرسل تلقائياً إلى بريده الإلكتروني', 'Teacher plan has email-only support — the reply is sent automatically to their inbox')}
                </div>
              )}
              <div className="flex gap-2">
                <Input value={reply} onChange={(e) => setReply(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && !e.shiftKey && sendReply()} placeholder={detail.channel === 'chat' ? t('اكتب ردك...', 'Write your reply...') : t('اكتب الرد (يُرسل عبر البريد)...', 'Write the reply (sent by email)...')} className="h-11" />
                <Button onClick={sendReply} disabled={sending || !reply.trim()} className="gap-2 shrink-0" aria-label={t('إرسال الرد', 'Send reply')}>
                  {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                </Button>
              </div>

              <DialogFooter className="flex-wrap gap-2">
                {['open', 'pending', 'resolved', 'closed'].filter(s => s !== detail.status).map(s => (
                  <Button key={s} variant="outline" size="sm" onClick={() => setStatus(s)}>{t('تحديد كـ', 'Mark')} {stLabel(s, locale)}</Button>
                ))}
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      
    </div>
  );
}
