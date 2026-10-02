'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { CalendarClock, XCircle, HandCoins, Clock, ExternalLink, Building2 } from 'lucide-react';
import { toast } from 'sonner';
import { useRouter } from 'next/navigation';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const STATUS_MAP: Record<string, [string, string]> = {
  pending: ['قيد الانتظار', 'bg-amber-500'],
  confirmed: ['مؤكد', 'bg-emerald-500'],
  completed: ['منجز', 'bg-teal-500'],
  cancelled: ['ملغي', 'bg-gray-400'],
};

export default function MyBookingsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [bookings, setBookings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [cancelling, setCancelling] = useState(false);

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = () => {
    fetch(`${API}/my-bookings`, { headers: headers() })
      .then(r => r.json()).then(d => setBookings(d.bookings || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const cancel = async (id: string) => {
    if (!confirm(t('إلغاء هذا الحجز؟', 'Cancel this booking?'))) return;
    setCancelling(true);
    try {
      const res = await fetch(`${API}/my-bookings/${id}/cancel`, { method: 'POST', headers: headers() });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم إلغاء الحجز', 'Booking cancelled'));
      load();
    } catch { toast.error('Failed'); }
    setCancelling(false);
  };

  const fmt = (iso: string) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  };

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <CalendarClock className="h-6 w-6 text-teal-600" />
        </span>
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('حجوزاتي', 'My Bookings')}</h1>
          <p className="text-muted-foreground mt-1">{t('مواعيدك واستشاراتك المحجوزة مع المعلمين', 'Your appointments and consultations with teachers')}</p>
        </div>
      </div>

      {loading ? <CardSkeleton /> :
        bookings.length === 0 ? (
          <EmptyState icon={CalendarClock} title={t('لا توجد حجوزات', 'No bookings')} description={t('احجز موعداً أو استشارة من صفحة معلمك', "Book an appointment or consultation from your teacher's page")} />
        ) : (
          <div className="space-y-3">
            {bookings.map(b => {
              const [label, cls] = STATUS_MAP[b.status] || ['—', 'bg-gray-400'];
              return (
                <Card key={b.id} className="border-0 shadow-sm">
                  <CardContent className="p-5">
                    <div className="flex flex-wrap items-start gap-4">
                      <div className="flex h-11 w-11 items-center justify-center rounded-xl shrink-0" style={{ backgroundColor: b.kind === 'service' ? '#7c3aed15' : '#0d948815' }}>
                        {b.kind === 'service' ? <HandCoins className="h-5 w-5 text-violet-600" /> : <CalendarClock className="h-5 w-5 text-teal-700" />}
                      </div>
                      <div className="flex-1 min-w-[200px]">
                        <div className="flex items-center gap-2 flex-wrap">
                          <h3 className="font-semibold">{b.service?.title || t('موعد', 'Appointment')}</h3>
                          <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-white text-[11px] font-bold ${cls}`}>{label}</span>
                        </div>
                        <p className="text-sm text-gray-600 mt-1 flex items-center gap-1.5"><Clock className="h-3.5 w-3.5 text-gray-400" />{fmt(b.starts_at)}</p>
                        <p className="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                          <Building2 className="h-3 w-3" />{b.tenant?.name} · {t('مع', 'with')} {b.teacher?.name}
                        </p>
                        {b.kind === 'service' && b.price > 0 && <p className="text-xs text-gray-500 mt-1">{b.price} {b.currency}</p>}
                        {b.meeting_url && (
                          <a href={b.meeting_url} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1.5 text-xs text-teal-700 underline" dir="ltr">
                            <ExternalLink className="h-3 w-3" />{b.meeting_url}
                          </a>
                        )}
                      </div>
                      <div className="flex items-center gap-1.5">
                        {['pending', 'confirmed'].includes(b.status) && (
                          <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-amber-600" onClick={() => cancel(b.id)} disabled={cancelling}><XCircle className="h-3.5 w-3.5" />{cancelling ? t('...جارٍ الإلغاء', 'Cancelling...') : t('إلغاء', 'Cancel')}</Button>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
    </div>
  );
}
