'use client';

import { useEffect, useState, useCallback } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@/components/ui/empty-state';
import {
  BookOpen, CheckCircle2, XCircle, Clock, Search, Loader2,
  Mail, Phone, MapPin, Globe, BookMarked, AudioWaveform, MessageSquare, Eye,
} from 'lucide-react';
import { toast } from 'sonner';
import { countryName } from '@/lib/countries';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const TAJWEED_LABELS: Record<string, string> = {
  beginner: 'مبتدئ',
  intermediate: 'متوسط',
  advanced: 'متقدم',
  proficient: 'مجيد',
  ijazah: 'مجاز بإجازة',
};

type Request = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  city: string | null;
  country: string | null;
  memorized_juz: number | null;
  tajweed_level: string | null;
  message: string | null;
  plan_id: string | null;
  plan: { id: string; name: string; slug: string } | null;
  status: 'pending' | 'approved' | 'rejected';
  admin_notes: string | null;
  reviewed_at: string | null;
  created_at: string;
};

type Plan = { id: string; name: string; slug: string; is_active: boolean };

export default function QuranRequestsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [requests, setRequests] = useState<Request[]>([]);
  const [stats, setStats] = useState({ total: 0, pending: 0, approved: 0, rejected: 0 });
  const [plans, setPlans] = useState<Plan[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<Request | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [adminNote, setAdminNote] = useState('');
  const [selectedPlanId, setSelectedPlanId] = useState<string>('');

  const headers = useCallback(() => ({
    Accept: 'application/json',
  }), []);

  const load = useCallback(() => {
    const q = new URLSearchParams();
    if (statusFilter) q.set('status', statusFilter);
    if (search) q.set('search', search);
    setLoading(true);
    fetch(`${API}/admin/quran-teacher-requests?${q}`, { headers: headers() })
      .then((r) => r.json())
      .then((d) => {
        setRequests(d.data || []);
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, [statusFilter, search, headers]);

  const loadStats = useCallback(() => {
    fetch(`${API}/admin/quran-teacher-requests/stats`, { headers: headers() })
      .then((r) => r.json())
      .then(setStats)
      .catch(() => {});
  }, [headers]);

  const loadPlans = useCallback(() => {
    fetch(`${API}/admin/plans`, { headers: headers() })
      .then((r) => r.json())
      .then((d) => setPlans(d.plans || d.data || []))
      .catch(() => {});
  }, [headers]);

  useEffect(() => { load(); loadStats(); loadPlans(); }, [load, loadStats, loadPlans]);

  const updateStatus = async (id: string, status: 'approved' | 'rejected') => {
    setBusyId(id);
    try {
      const res = await fetch(`${API}/admin/quran-teacher-requests/${id}`, {
        method: 'PUT',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        body: JSON.stringify({
          status,
          admin_notes: adminNote || null,
          plan_id: status === 'approved' && selectedPlanId ? selectedPlanId : null,
        }),
      });
      const data = await res.json();
      if (res.ok) {
        toast.success(data.message);
        setDetail(null);
        setAdminNote('');
        setSelectedPlanId('');
        load();
        loadStats();
      } else {
        toast.error(data.message || 'حدث خطأ');
      }
    } catch {
      toast.error('حدث خطأ في الاتصال');
    } finally {
      setBusyId(null);
    }
  };

  const statusBadge = (s: string) => {
    const map: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
      pending:  { label: t('قيد المراجعة', 'Pending'),  color: 'bg-amber-100 text-amber-700', icon: <Clock className="h-3 w-3" /> },
      approved: { label: t('تمت الموافقة', 'Approved'), color: 'bg-green-100 text-green-700', icon: <CheckCircle2 className="h-3 w-3" /> },
      rejected: { label: t('مرفوض', 'Rejected'),       color: 'bg-red-100 text-red-700',    icon: <XCircle className="h-3 w-3" /> },
    };
    const info = map[s] || map.pending;
    return (
      <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${info.color}`}>
        {info.icon} {info.label}
      </span>
    );
  };

  return (
    <div className="space-y-6" dir="rtl">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-black text-slate-900 flex items-center gap-2">
          <BookOpen className="h-6 w-6 text-emerald-600" />
          {t('طلبات مدرسي القرءان', 'Quran Teacher Requests')}
        </h1>
        <p className="text-sm text-slate-500 mt-1">{t('طلبات الحساب لمدرسي القرءان الكريم', 'Free account requests for Quran teachers')}</p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: t('الكل', 'Total'),     value: stats.total,    color: 'text-slate-900' },
          { label: t('قيد المراجعة', 'Pending'),  value: stats.pending,  color: 'text-amber-600' },
          { label: t('تمت الموافقة', 'Approved'), value: stats.approved, color: 'text-green-600' },
          { label: t('مرفوض', 'Rejected'),       value: stats.rejected, color: 'text-red-600' },
        ].map((s) => (
          <Card key={s.label}>
            <CardContent className="p-4 text-center">
              <div className={`text-2xl font-black ${s.color}`}>{s.value}</div>
              <div className="text-xs text-slate-500 mt-1">{s.label}</div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-[200px]">
          <Search className="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
          <Input
            placeholder={t('بحث بالاسم أو البريد...', 'Search by name or email...')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pr-10"
          />
        </div>
        <div className="flex gap-1.5">
          {['', 'pending', 'approved', 'rejected'].map((s) => (
            <Button
              key={s}
              size="sm"
              variant={statusFilter === s ? 'default' : 'outline'}
              onClick={() => setStatusFilter(s)}
              className={statusFilter === s ? 'bg-teal-600 hover:bg-teal-700' : ''}
            >
              {s === '' ? t('الكل', 'All') : statusBadge(s)}
            </Button>
          ))}
        </div>
      </div>

      {/* List */}
      {loading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-24 animate-pulse rounded-2xl border bg-muted/30" />
          ))}
        </div>
      ) : requests.length === 0 ? (
        <EmptyState
          icon={BookOpen}
          title={t('لا توجد طلبات', 'No requests')}
          description={t('لم يتم تقديم أي طلب بعد', 'No requests have been submitted yet')}
        />
      ) : (
        <div className="space-y-3">
          {requests.map((req) => (
            <Card
              key={req.id}
              className="cursor-pointer hover:shadow-md transition-shadow"
              onClick={() => {
                setDetail(req);
                setAdminNote(req.admin_notes || '');
                setSelectedPlanId(req.plan_id || '');
              }}
            >
              <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-bold text-slate-900">{req.name}</span>
                      {statusBadge(req.status)}
                      {req.plan && (
                        <Badge variant="outline" className="text-xs border-emerald-200 text-emerald-700">
                          {req.plan.name}
                        </Badge>
                      )}
                    </div>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                      <span className="flex items-center gap-1"><Mail className="h-3 w-3" />{req.email}</span>
                      {req.phone && <span className="flex items-center gap-1"><Phone className="h-3 w-3" />{req.phone}</span>}
                      {req.city && <span className="flex items-center gap-1"><MapPin className="h-3 w-3" />{req.city}</span>}
                      {req.country && <span className="flex items-center gap-1"><Globe className="h-3 w-3" />{countryName(req.country, 'ar')}</span>}
                      {req.memorized_juz && <span className="flex items-center gap-1"><BookMarked className="h-3 w-3" />{t('يحفظ ', 'Memorized: ')}{req.memorized_juz} جزء</span>}
                      {req.tajweed_level && <span className="flex items-center gap-1"><AudioWaveform className="h-3 w-3" />{TAJWEED_LABELS[req.tajweed_level] || req.tajweed_level}</span>}
                    </div>
                    {req.message && (
                      <p className="text-xs text-slate-400 mt-1.5 line-clamp-1 flex items-center gap-1">
                        <MessageSquare className="h-3 w-3 shrink-0" />{req.message}
                      </p>
                    )}
                  </div>
                  <span className="text-xs text-slate-400 shrink-0">
                    {new Date(req.created_at).toLocaleDateString('ar-SA')}
                  </span>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Detail Dialog */}
      {detail && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={() => setDetail(null)}>
          <div className="bg-white rounded-2xl shadow-xl max-w-lg w-full mx-4 p-6 max-h-[85vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-black text-slate-900">{detail.name}</h2>
              {statusBadge(detail.status)}
            </div>

            <div className="space-y-3 text-sm">
              <InfoRow icon={<Mail className="h-4 w-4" />} label="البريد" value={detail.email} />
              {detail.phone && <InfoRow icon={<Phone className="h-4 w-4" />} label="الهاتف" value={detail.phone} />}
              {detail.city && <InfoRow icon={<MapPin className="h-4 w-4" />} label="المدينة" value={detail.city} />}
              {detail.country && <InfoRow icon={<Globe className="h-4 w-4" />} label="البلد" value={countryName(detail.country, 'ar')} />}
              {detail.memorized_juz && <InfoRow icon={<BookMarked className="h-4 w-4" />} label="التحفيظ" value={`${detail.memorized_juz} جزء`} />}
              {detail.tajweed_level && <InfoRow icon={<AudioWaveform className="h-4 w-4" />} label="مستوى التجويد" value={TAJWEED_LABELS[detail.tajweed_level] || detail.tajweed_level} />}
              {detail.message && (
                <div className="bg-slate-50 rounded-xl p-3">
                  <div className="flex items-center gap-1.5 text-xs font-bold text-slate-500 mb-1">
                    <MessageSquare className="h-3.5 w-3.5" /> الرسالة
                  </div>
                  <p className="text-slate-700 text-sm whitespace-pre-wrap">{detail.message}</p>
                </div>
              )}

              {detail.status === 'pending' && (
                <>
                  {/* Plan assignment */}
                  <div>
                    <label className="text-xs font-bold text-slate-500 mb-1 block">تعيين خطة اشتراك (اختياري)</label>
                    <select
                      className="w-full border border-slate-200 rounded-xl p-2.5 text-sm bg-white"
                      value={selectedPlanId}
                      onChange={(e) => setSelectedPlanId(e.target.value)}
                    >
                      <option value="">— بدون خطة —</option>
                      {plans.filter((p) => p.is_active).map((p) => (
                        <option key={p.id} value={p.id}>{p.name}</option>
                      ))}
                    </select>
                  </div>

                  {/* Admin notes */}
                  <div>
                    <label className="text-xs font-bold text-slate-500 mb-1 block">ملاحظات المدير</label>
                    <textarea
                      className="w-full border border-slate-200 rounded-xl p-2.5 text-sm resize-none"
                      rows={2}
                      value={adminNote}
                      onChange={(e) => setAdminNote(e.target.value)}
                      placeholder="ملاحظات داخلية..."
                    />
                  </div>

                  <div className="flex gap-2 pt-2">
                    <Button
                      className="flex-1 bg-green-600 hover:bg-green-700 text-white"
                      disabled={busyId === detail.id}
                      onClick={() => updateStatus(detail.id, 'approved')}
                    >
                      {busyId === detail.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <><CheckCircle2 className="h-4 w-4 me-1" /> تفعيل الحساب</>}
                    </Button>
                    <Button
                      variant="outline"
                      className="flex-1 text-red-600 border-red-200 hover:bg-red-50"
                      disabled={busyId === detail.id}
                      onClick={() => updateStatus(detail.id, 'rejected')}
                    >
                      {busyId === detail.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <><XCircle className="h-4 w-4 me-1" /> رفض</>}
                    </Button>
                  </div>
                </>
              )}

              {/* Show plan/notes for already-reviewed requests */}
              {detail.status !== 'pending' && detail.admin_notes && (
                <div className="bg-slate-50 rounded-xl p-3">
                  <div className="text-xs font-bold text-slate-500 mb-1">ملاحظات المدير</div>
                  <p className="text-slate-700 text-sm whitespace-pre-wrap">{detail.admin_notes}</p>
                </div>
              )}
            </div>

            <button onClick={() => setDetail(null)} className="absolute top-4 left-4 text-slate-400 hover:text-slate-600">✕</button>
          </div>
        </div>
      )}
    </div>
  );
}

function InfoRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-slate-400">{icon}</span>
      <span className="text-slate-500 text-xs">{label}:</span>
      <span className="text-slate-800 font-medium">{value}</span>
    </div>
  );
}
