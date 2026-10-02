'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { Loader2, CheckCircle2, XCircle, GraduationCap, Mail } from 'lucide-react';
import { toast } from 'sonner';
import { useRouter } from 'next/navigation';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function InvitePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const { locale } = useLocale();
  const { check, user, login } = useAuth();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [status, setStatus] = useState<'checking' | 'logged_out' | 'ready' | 'accepted' | 'error'>('checking');
  const [message, setMessage] = useState('');
  const [tenantName, setTenantName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { setStatus(check() ? 'ready' : 'logged_out'); }, [check()]);

  const accept = async () => {
    setBusy(true);
    try {
      const tokenStr = localStorage.getItem('lms_authed');
      const res = await fetch(`${API}/teacher/staff/invitations/${token}/accept`, {
        method: 'POST',
        headers: { Accept: 'application/json' },
      });
      const d = await res.json();
      if (!res.ok) { setMessage(d.message || t('تعذر قبول الدعوة', 'Could not accept invitation')); setStatus('error'); return; }
      setTenantName(d.tenant?.name || '');
      setStatus('accepted');
      toast.success(t('تم قبول الدعوة!', 'Invitation accepted!'));
    } catch {
      setMessage(t('تعذر الاتصال بالخادم', 'Cannot reach server'));
      setStatus('error');
    }
    setBusy(false);
  };

  const doLogin = async () => {
    if (!email.trim() || !password) { toast.error(t('أدخل البريد وكلمة المرور', 'Enter email and password')); return; }
    setBusy(true);
    try {
      await login(email, password);
      toast.success(t('تم تسجيل الدخول', 'Logged in'));
      setStatus('ready');
    } catch {
      toast.error(t('بيانات الدخول غير صحيحة', 'Invalid credentials'));
    }
    setBusy(false);
  };

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <Card className="w-full max-w-md border-0 shadow-lg">
        <CardContent className="p-8">
          <div className="flex flex-col items-center text-center mb-6">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-500 shadow-lg mb-4">
              <GraduationCap className="h-7 w-7 text-white" />
            </div>
            <h1 className="text-xl font-bold">{t('دعوة للانضمام كمعلم', 'Teacher invitation')}</h1>
            <p className="text-sm text-gray-500 mt-1">
              {status === 'accepted' && tenantName ? t(`تم انضمامك إلى «${tenantName}» بنجاح`, 'You joined the institution successfully') : t('تمت دعوتك للانضمام إلى منصة تعليمية', 'You have been invited to join an educational platform')}
            </p>
          </div>

          {status === 'checking' && (
            <div className="flex justify-center py-8"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>
          )}

          {status === 'logged_out' && (
            <div className="space-y-4">
              <div className="flex items-center gap-3 p-4 rounded-xl bg-teal-50 text-teal-700 text-sm">
                <Mail className="h-5 w-5 shrink-0" />
                {t('سجل دخولك أولاً ثم أعد فتح الرابط، أو سجل الدخول هنا للقبول مباشرة', 'Log in first, then accept the invitation here')}
              </div>
              <div className="space-y-2"><Label>{t('البريد الإلكتروني', 'Email')}</Label><Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="h-11" dir="ltr" placeholder="you@example.com" /></div>
              <div className="space-y-2"><Label>{t('كلمة المرور', 'Password')}</Label><Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className="h-11" /></div>
              <Button onClick={doLogin} disabled={busy} className="w-full h-11 rounded-full gap-2">{busy && <Loader2 className="h-4 w-4 animate-spin" />}{t('تسجيل الدخول والقبول', 'Log in & accept')}</Button>
              <p className="text-center text-xs text-gray-400">
                {t('ليس لديك حساب؟ سجل أولاً من', 'No account? Register first from')}{' '}
                <a href={`/register?redirect=/invite/${token}`} className="text-teal-700 underline">{t('صفحة التسجيل', 'the register page')}</a>
              </p>
            </div>
          )}

          {status === 'ready' && (
            <div className="space-y-4">
              <p className="text-sm text-gray-600 text-center">{t('مرحباً', 'Welcome')} {user?.name} — {t('اضغط للانضمام كمدرس في المؤسسة', 'press below to join as a teacher')}</p>
              <Button onClick={accept} disabled={busy} className="w-full h-11 rounded-full gap-2">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('قبول الدعوة', 'Accept invitation')}</Button>
            </div>
          )}

          {status === 'accepted' && (
            <div className="space-y-4">
              <div className="flex flex-col items-center gap-2 py-4">
                <CheckCircle2 className="h-12 w-12 text-emerald-500" />
                <p className="font-semibold text-emerald-600">{t('مبروك! أنت الآن معلم في المؤسسة', 'Congratulations! You are now a teacher')}</p>
              </div>
              <Button onClick={() => router.push('/teacher/dashboard')} className="w-full h-11 rounded-full">{t('الذهاب إلى منصتك', 'Go to your platform')}</Button>
            </div>
          )}

          {status === 'error' && (
            <div className="space-y-4">
              <div className="flex flex-col items-center gap-2 py-4">
                <XCircle className="h-12 w-12 text-red-400" />
                <p className="text-sm text-red-500 text-center">{message}</p>
              </div>
              <Button onClick={() => router.push('/')} variant="outline" className="w-full h-11 rounded-full">{t('العودة للرئيسية', 'Back to home')}</Button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
