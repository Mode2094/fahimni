'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loader2, Video, Calendar, Users, Square, ExternalLink, Clock, MonitorPlay } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function LiveJoinPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [session, setSession] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    if (!token) { setLoading(false); return; }
    fetch(`${API}/live-sessions/${id}`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then(d => setSession(d.session || null)).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (id && check()) load(); }, [id, check]);

  if (loading) return <div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;

  if (!session) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4">
        <Card className="w-full max-w-md p-8 text-center"><CardContent className="space-y-4">
          <Video className="h-12 w-12 mx-auto text-gray-300" />
          <h1 className="text-2xl font-bold">{t('الجلسة غير موجودة', 'Session not found')}</h1>
          <p className="text-gray-500 text-sm">{t('تأكد من الرابط أو سجل الدخول أولاً', 'Check the link or log in first')}</p>
        </CardContent></Card>
      </div>
    );
  }

  const isLive = session.status === 'live';
  const statusLabel = isLive ? t('مباشر الآن', 'Live now') : session.status === 'scheduled' ? t('مجدولة', 'Scheduled') : session.status === 'ended' ? t('منتهية', 'Ended') : t('ملغاة', 'Cancelled');

  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-900 to-slate-800 py-10 px-4">
      <div className="max-w-4xl mx-auto">
        <div className="flex items-center gap-2 mb-6 text-white/70 text-sm">
          <Video className="h-4 w-4" />{t('حصة مباشرة', 'Live session')}
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold ${isLive ? 'bg-red-500 text-white' : 'bg-white/10 text-white/80'}`}>
            {isLive && <span className="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />}{statusLabel}
          </span>
        </div>

        <Card className="border-0 shadow-2xl overflow-hidden bg-white">
          {session.meeting_url && session.status !== 'cancelled' ? (
            <div className="aspect-video w-full bg-slate-950 flex flex-col items-center justify-center gap-4 p-6 text-center">
              <div className="flex h-20 w-20 items-center justify-center rounded-full bg-teal-500/20">
                <MonitorPlay className="h-10 w-10 text-teal-400" />
              </div>
              <p className="text-white text-lg font-semibold">
                {isLive ? t('الحصة جاهزة — اضغط للدخول', 'Session is ready — click to join') : t('حصة Google Meet', 'Google Meet session')}
              </p>
              {!isLive && session.scheduled_at && <p className="text-white/50 text-sm">{new Date(session.scheduled_at).toLocaleString()}</p>}
              <a href={session.meeting_url} target="_blank" rel="noopener noreferrer"
                className="inline-flex items-center gap-2 rounded-full bg-teal-600 hover:bg-teal-500 text-white text-base font-bold px-8 py-3 transition">
                <ExternalLink className="h-5 w-5" />{t('دخول الحصة', 'Join Class')}
              </a>
              <p className="text-white/40 text-xs">{t('سينقلك الرابط إلى Google Meet', 'The link will open Google Meet')}</p>
            </div>
          ) : session.status === 'scheduled' ? (
            <div className="aspect-video w-full bg-slate-950 flex flex-col items-center justify-center gap-4">
              <div className="flex h-20 w-20 items-center justify-center rounded-full bg-teal-500/20">
                <Clock className="h-10 w-10 text-teal-400" />
              </div>
              <p className="text-white text-lg font-semibold">{t('الحصة لم تبدأ بعد', 'Session has not started yet')}</p>
              {session.scheduled_at && <p className="text-white/50 text-sm">{new Date(session.scheduled_at).toLocaleString()}</p>}
            </div>
          ) : (
            <div className="aspect-video w-full bg-slate-950 flex flex-col items-center justify-center gap-4">
              <div className="flex h-20 w-20 items-center justify-center rounded-full bg-white/10">
                <Square className="h-8 w-8 text-white/40" />
              </div>
              <p className="text-white text-lg font-semibold">{statusLabel}</p>
              {session.record_url && <a href={session.record_url} target="_blank" className="text-teal-400 underline text-sm">{t('عرض التسجيل', 'View recording')}</a>}
            </div>
          )}
          <CardContent className="p-6">
            <div className="flex flex-wrap items-start gap-4">
              <div>
                <h1 className="text-2xl font-bold">{session.title}</h1>
                {session.description && <p className="text-gray-500 mt-1">{session.description}</p>}
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 mt-4 text-sm text-gray-500">
              <span className="flex items-center gap-1.5"><Calendar className="h-4 w-4" />{session.scheduled_at ? new Date(session.scheduled_at).toLocaleString() : t('فوري', 'Now')}</span>
              <span className="flex items-center gap-1.5"><Users className="h-4 w-4" />{session.max_participants} {t('مشارك', 'participants')}</span>
              <Badge variant="outline" className="text-xs">Google Meet</Badge>
            </div>
            {isLive && session.meeting_url && (
              <div className="mt-5 flex flex-wrap gap-2">
                <a href={session.meeting_url} target="_blank" rel="noopener noreferrer"><Button className="gap-2 rounded-full bg-red-500 hover:bg-red-600 text-white"><ExternalLink className="h-4 w-4" />{t('دخول الحصة', 'Join Class')}</Button></a>
                <Button variant="outline" className="gap-2 rounded-full" onClick={() => { navigator.clipboard.writeText(window.location.href); toast.success(t('تم النسخ', 'Copied')); }}>{t('نسخ رابط الصفحة', 'Copy page link')}</Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}