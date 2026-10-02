'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Loader2, CalendarClock, HandCoins, Clock, CheckCircle2, ChevronRight, User, GraduationCap, MessageSquareQuote, Lock } from 'lucide-react';
import { toast } from 'sonner';
import { useRouter } from 'next/navigation';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Slot = { starts_at: string; ends_at: string; start_time: string; end_time: string };
type Service = { id: string; type: string; title: string; description: string; price: string; currency: string; duration_minutes: number; teacher: { id: string; name: string; avatar_url: string } };

export default function BookPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const { check } = useAuth();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [loggedIn, setLoggedIn] = useState(false);
  const [teachers, setTeachers] = useState<any[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [mode, setMode] = useState<'appointment' | 'service'>('appointment');
  const [selectedService, setSelectedService] = useState<Service | null>(null);
  const [teacherId, setTeacherId] = useState('');
  const [date, setDate] = useState('');
  const [slots, setSlots] = useState<Slot[]>([]);
  const [selectedSlot, setSelectedSlot] = useState<Slot | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [gateway, setGateway] = useState('paytabs');
  const [gateways, setGateways] = useState<Record<string, any>>({});
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [bookingResult, setBookingResult] = useState<any>(null);

  useEffect(() => {
    setLoggedIn(!!localStorage.getItem('lms_authed'));
    fetch(`${API}/public/${slug}/book/teachers`).then(r => r.json()).then(d => { setTeachers(d.teachers || []); if (d.teachers?.[0]) setTeacherId(d.teachers[0].id); }).catch(() => {});
    fetch(`${API}/public/${slug}/book/services`).then(r => r.json()).then(d => setServices(d.services || [])).catch(() => {});
    fetch(`${API}/payment-gateways`).then(r => r.json()).then(d => setGateways(d || {})).catch(() => {});
  }, [slug]);

  useEffect(() => {
    if (!teacherId || !date) { setSlots([]); return; }
    setSlotsLoading(true);
    setSelectedSlot(null);
    const qs = new URLSearchParams({ date, teacher_id: teacherId });
    if (mode === 'service' && selectedService) qs.set('service_id', selectedService.id);
    fetch(`${API}/public/${slug}/book/slots?${qs}`).then(r => r.json()).then(d => setSlots(d.slots || [])).catch(() => setSlots([])).finally(() => setSlotsLoading(false));
  }, [teacherId, date, mode, selectedService?.id, slug]);

  const pickService = (s: Service) => {
    setMode('service');
    setSelectedService(s);
    setTeacherId(s.teacher.id);
    setSelectedSlot(null);
  };

  const startOver = () => { setMode('appointment'); setSelectedService(null); setSelectedSlot(null); setBookingResult(null); };

  const submit = async () => {
    if (!teacherId || !selectedSlot) { toast.error(t('اختر الموعد', 'Pick a time slot')); return; }
    if (mode === 'service' && !selectedService) { toast.error(t('اختر الخدمة', 'Pick a service')); return; }
    setSubmitting(true);
    try {
      const body: any = { teacher_id: teacherId, starts_at: selectedSlot.starts_at, notes: notes || null };
      if (mode === 'service') {
        body.service_id = selectedService!.id;
        body.gateway = gateway;
      }
      const res = await fetch(`${API}/public/${slug}/book`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('تعذر الحجز', 'Booking failed')); return; }
      setBookingResult(d);
      toast.success(t('تم الحجز بنجاح', 'Booking created successfully'));
      if (d.payment?.redirect_url) {
        window.location.href = d.payment.redirect_url;
        return;
      }
    } catch {
      toast.error(t('تعذر الاتصال', 'Cannot connect'));
    }
    setSubmitting(false);
  };

  const selectedTeacher = teachers.find((x: any) => x.id === teacherId);
  const availableGateways = Object.entries(gateways).filter(([, g]: any) => g.enabled);

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b border-gray-100 sticky top-0 z-40">
        <div className="max-w-3xl mx-auto px-4 h-16 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <button onClick={() => router.push(`/t/${slug}`)} className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100"><ChevronRight className="h-4 w-4" /></button>
            <h1 className="font-bold">{t('حجز موعد', 'Book an appointment')}</h1>
          </div>
          {!loggedIn && (
            <a href={`/register?redirect=/t/${slug}/book`}><Button size="sm" className="rounded-full gap-1.5"><Lock className="h-3.5 w-3.5" />{t('تسجيل الدخول للحجز', 'Log in to book')}</Button></a>
          )}
        </div>
      </header>

      <main className="max-w-3xl mx-auto px-4 py-8 space-y-6">
        {!loggedIn && (
          <div className="rounded-xl bg-teal-50 border border-teal-200 p-4 text-sm text-teal-700 flex items-center gap-2">
            <Lock className="h-4 w-4 shrink-0" />
            {t('تحتاج تسجيل دخول لإتمام الحجز. سجل أو أنشئ حساباً من زر تسجيل الدخول بالأعلى.', 'You need to log in to complete a booking. Register or log in from the button above.')}
          </div>
        )}

        {bookingResult ? (
          <Card className="border-0 shadow-sm">
            <CardContent className="p-8 flex flex-col items-center text-center gap-3">
              <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50"><CheckCircle2 className="h-8 w-8 text-emerald-500" /></div>
              <h2 className="text-xl font-bold">{t('تم الحجز بنجاح', 'Booking confirmed')}</h2>
              <p className="text-sm text-gray-500">{t('ستصلك رسالة عند تأكيد المعلم للموعد', 'You will be notified when the teacher confirms')}</p>
              {bookingResult.payment?.status === 'pending' && bookingResult.payment?.redirect_url && (
                <Button className="rounded-full mt-2" onClick={() => { window.location.href = bookingResult.payment.redirect_url; }}>{t('إتمام الدفع', 'Complete payment')}</Button>
              )}
              <Button variant="outline" className="rounded-full" onClick={() => router.push(`/t/${slug}`)}>{t('العودة للموقع', 'Back to site')}</Button>
            </CardContent>
          </Card>
        ) : (
          <>
            {/* Step 1: choose service or appointment */}
            <Card className="border-0 shadow-sm">
              <CardContent className="p-6">
                <h3 className="font-semibold mb-1">{t('١. اختر نوع الحجز', '1. Choose booking type')}</h3>
                <div className="grid sm:grid-cols-2 gap-3">
                  <button onClick={startOver} className={`flex items-start gap-3 p-4 rounded-xl border text-right transition ${mode === 'appointment' ? 'border-teal-300 bg-teal-50' : 'border-gray-200 hover:border-gray-300'}`}>
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-100 shrink-0"><CalendarClock className="h-5 w-5 text-teal-700" /></div>
                    <div><p className="font-semibold text-sm">{t('موعد', 'Appointment')}</p><p className="text-xs text-gray-500 mt-0.5">{t('حجز موعد مباشر مع المعلم', 'Book a time directly with the teacher')}</p></div>
                  </button>
                  <button onClick={() => setMode('service')} className={`flex items-start gap-3 p-4 rounded-xl border text-right transition ${mode === 'service' && !selectedService ? 'border-violet-300 bg-violet-50' : 'border-gray-200 hover:border-gray-300'}`}>
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 shrink-0"><HandCoins className="h-5 w-5 text-violet-600" /></div>
                    <div><p className="font-semibold text-sm">{t('خدمة مدفوعة', 'Paid service')}</p><p className="text-xs text-gray-500 mt-0.5">{t('استشارة أو جلسة فردية', 'Consultation or 1-on-1 session')}</p></div>
                  </button>
                </div>
              </CardContent>
            </Card>

            {/* Step 2: paid service selection */}
            {mode === 'service' && (
              <Card className="border-0 shadow-sm">
                <CardContent className="p-6">
                  <h3 className="font-semibold mb-3">{t('٢. اختر الخدمة', '2. Choose a service')}</h3>
                  {services.length === 0 ? (
                    <p className="text-sm text-gray-400">{t('لا توجد خدمات متاحة حالياً', 'No services available yet')}</p>
                  ) : (
                    <div className="space-y-3">
                      {services.map(s => (
                        <button key={s.id} onClick={() => pickService(s)} className={`w-full flex items-start gap-3 p-4 rounded-xl border text-right transition ${selectedService?.id === s.id ? 'border-violet-300 bg-violet-50' : 'border-gray-200 hover:border-gray-300'}`}>
                          <div className="flex h-10 w-10 items-center justify-center rounded-xl shrink-0" style={{ backgroundColor: s.type === 'consultation' ? '#0d948815' : '#7c3aed15' }}>
                            {s.type === 'consultation' ? <MessageSquareQuote className="h-5 w-5 text-teal-700" /> : <GraduationCap className="h-5 w-5 text-violet-600" />}
                          </div>
                          <div className="flex-1">
                            <div className="flex items-center justify-between gap-2 flex-wrap">
                              <p className="font-semibold text-sm">{s.title}</p>
                              <span className="font-bold text-violet-700 text-sm">{s.price} {s.currency}</span>
                            </div>
                            {s.description && <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{s.description}</p>}
                            <div className="flex items-center gap-3 text-xs text-gray-400 mt-1">
                              <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{s.duration_minutes} {t('دقيقة', 'min')}</span>
                              <span className="flex items-center gap-1"><User className="h-3 w-3" />{s.teacher.name}</span>
                            </div>
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>
            )}

            {/* Step 3: teacher + date + slot */}
            {(mode === 'appointment' || selectedService) && (
              <Card className="border-0 shadow-sm">
                <CardContent className="p-6 space-y-4">
                  <h3 className="font-semibold">{t('٣. اختر الموعد', '3. Choose a time')}</h3>
                  <div className="grid sm:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label>{t('المعلم', 'Teacher')}</Label>
                      <select value={teacherId} onChange={(e) => { setTeacherId(e.target.value); setSelectedSlot(null); }} className="h-11 border border-gray-200 rounded-lg px-3 w-full text-sm bg-white">
                        {teachers.map((x: any) => <option key={x.id} value={x.id}>{x.name}</option>)}
                      </select>
                    </div>
                    <div className="space-y-2">
                      <Label>{t('التاريخ', 'Date')}</Label>
                      <Input type="date" min={new Date().toISOString().slice(0, 10)} value={date} onChange={(e) => { setDate(e.target.value); setSelectedSlot(null); }} className="h-11" />
                    </div>
                  </div>
                  {selectedTeacher && (
                    <p className="text-xs text-gray-500 flex items-center gap-1.5"><User className="h-3 w-3" />{t('ستحجز مع', 'You are booking with')}: <b>{selectedTeacher.name}</b>{selectedService ? ` · ${selectedService.title}` : ''}</p>
                  )}
                  {date && (
                    <div>
                      <Label>{t('الأوقات المتاحة', 'Available slots')}</Label>
                      {slotsLoading ? (
                        <div className="flex justify-center py-6"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div>
                      ) : slots.length === 0 ? (
                        <p className="text-sm text-gray-400 py-4 text-center">{t('لا توجد أوقات متاحة في هذا اليوم', 'No available slots on this day')}</p>
                      ) : (
                        <div className="grid grid-cols-3 sm:grid-cols-4 gap-2 mt-2">
                          {slots.map(sl => (
                            <button key={sl.starts_at} onClick={() => setSelectedSlot(sl)} className={`py-2.5 rounded-xl border text-sm font-medium transition ${selectedSlot?.starts_at === sl.starts_at ? 'border-blue-400 bg-teal-600 text-white' : 'border-gray-200 hover:border-teal-300'}`}>
                              {sl.start_time}
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                  {mode === 'service' && selectedService && (
                    <div className="space-y-2">
                      <Label>{t('ملاحظات (اختياري)', 'Notes (optional)')}</Label>
                      <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="min-h-[60px]" placeholder={t('مثال: عندي سؤال حول الجبر', 'e.g. I have a question about algebra')} />
                    </div>
                  )}
                  {mode === 'service' && selectedService && availableGateways.length > 0 && (
                    <div className="space-y-2">
                      <Label>{t('طريقة الدفع', 'Payment method')}</Label>
                      <div className="flex gap-2 flex-wrap">
                        {availableGateways.map(([key, g]: any) => (
                          <button key={key} onClick={() => setGateway(key)} className={`px-4 py-2.5 rounded-xl border text-sm font-medium capitalize transition ${gateway === key ? 'border-blue-400 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500'}`}>
                            {key}
                          </button>
                        ))}
                      </div>
                    </div>
                  )}
                  {selectedSlot && (
                    <div className="flex items-center justify-between gap-3 p-4 rounded-xl bg-gray-50">
                      <div>
                        <p className="font-semibold text-sm">{t('الموعد المحدد', 'Selected time')}</p>
                        <p className="text-xs text-gray-500 mt-0.5">{new Date(selectedSlot.starts_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })}</p>
                      </div>
                      <Button onClick={submit} disabled={submitting} className="rounded-full gap-2">
                        {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                        {mode === 'service' ? `${t('احجز وادفع', 'Book & pay')} ${selectedService?.price} ${selectedService?.currency}` : t('تأكيد الحجز', 'Confirm booking')}
                      </Button>
                    </div>
                  )}
                </CardContent>
              </Card>
            )}
          </>
        )}
      </main>
    </div>
  );
}
