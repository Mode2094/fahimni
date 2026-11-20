'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BookOpen, KeyRound, CheckCircle2, AlertCircle, Loader2, Eye, EyeOff } from 'lucide-react';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Stage = 'form' | 'working' | 'ok' | 'error';

/**
 * Map a failed reset response to a locale-aware message. The status code is the
 * reliable signal (400 = bad/expired token, 422 = bad input, 429 = throttled);
 * we only fall back to the server text when nothing else applies, so the UI
 * stays Arabic even if the backend returns English strings.
 */
function resetErrorMessage(status: number | undefined, serverMessage: unknown, t: (ar: string, en: string) => string): string {
  if (status === 400) {
    return t('الرابط غير صالح أو انتهت صلاحيته. اطلب رابط إعادة تعيين جديد.', 'This reset link is invalid or has expired. Request a new one.');
  }
  if (status === 422) {
    return t('تأكد من أن كلمة المرور 8 أحرف على الأقل وأنها مطابقة للتأكيد.', 'Make sure the password is at least 8 characters and matches the confirmation.');
  }
  if (status === 429) {
    return t('محاولات كثيرة — انتظر قليلاً ثم أعد المحاولة.', 'Too many attempts — wait a moment and try again.');
  }

  return typeof serverMessage === 'string' && serverMessage ? serverMessage : t('تعذّر تعيين كلمة المرور.', 'Could not reset the password.');
}

function ResetPasswordInner() {
  const { locale } = useLocale();
  const router = useRouter();
  const params = useSearchParams();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const token = params.get('token') || '';
  const email = params.get('email') || '';

  const [stage, setStage] = useState<Stage>('form');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [error, setError] = useState('');
  const [target, setTarget] = useState('/teacher/login');

  // A reset link without token+email is unusable — surface it immediately
  // instead of letting the user type a password that can never be applied.
  useEffect(() => {
    if (!token || !email) {
      setStage('error');
      setError(t('رابط إعادة التعيين غير مكتمل. اطلب رسالة جديدة.', 'This reset link is incomplete. Request a new one.'));
    }
  }, [token, email]); // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (password.length < 8) {
      setError(t('كلمة المرور يجب أن تكون 8 أحرف على الأقل.', 'Password must be at least 8 characters.'));
      return;
    }
    if (password !== confirm) {
      setError(t('كلمتا المرور غير متطابقتين.', 'The passwords do not match.'));
      return;
    }

    setStage('working');
    try {
      const r = await fetch(`${API}/auth/reset-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, token, password, password_confirmation: confirm }),
      });
      const d = await r.json().catch(() => ({}));

      if (!r.ok) {
        setStage('error');
        setError(resetErrorMessage(r.status, d?.message, t));
        return;
      }

      if (d?.login_path) setTarget(d.login_path);
      setStage('ok');
    } catch {
      setStage('error');
      setError(t('خطأ في الشبكة — حاول مرة أخرى.', 'Network error — please try again.'));
    }
  };

  const backToLogin = target;

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <Card className="w-full max-w-md p-8 animate-in">
        <CardContent className="space-y-6 text-center">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary shadow-lg">
            <BookOpen className="h-7 w-7 text-white" />
          </div>

          {stage === 'ok' && (
            <>
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                <CheckCircle2 className="h-8 w-8 text-green-600" />
              </div>
              <h1 className="text-2xl font-bold">{t('تم تعيين كلمة المرور', 'Password updated')}</h1>
              <p className="text-gray-500">{t('يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة.', 'You can now log in with your new password.')}</p>
              <Button className="w-full h-11" onClick={() => router.push(backToLogin)}>
                {t('تسجيل الدخول', 'Log in')}
              </Button>
            </>
          )}

          {stage === 'error' && (
            <>
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50">
                <AlertCircle className="h-8 w-8 text-red-500" />
              </div>
              <h1 className="text-2xl font-bold">{t('تعذّر إعادة التعيين', 'Reset failed')}</h1>
              <p className="text-gray-500">{error}</p>
              <Link href="/forgot-password" className="text-sm font-medium text-primary hover:underline">
                {t('طلب رابط جديد', 'Request a new link')}
              </Link>
            </>
          )}

          {(stage === 'form' || stage === 'working') && (
            <form onSubmit={submit} className="space-y-5 text-start">
              <div className="text-center space-y-1">
                <h1 className="text-2xl font-bold">{t('تعيين كلمة مرور جديدة', 'Set a new password')}</h1>
                {email && <p className="text-sm text-gray-500" dir="ltr">{email}</p>}
              </div>

              {error && (
                <div className="flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                  <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="password">{t('كلمة المرور الجديدة', 'New password')}</Label>
                <div className="relative">
                  <Input
                    id="password"
                    type={show ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    minLength={8}
                    autoComplete="new-password"
                    className="h-11"
                    dir="ltr"
                  />
                  <button
                    type="button"
                    onClick={() => setShow((s) => !s)}
                    className="absolute inset-y-0 end-2 flex items-center px-2 text-gray-400"
                    aria-label={t('إظهار كلمة المرور', 'Show password')}
                  >
                    {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                  </button>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="confirm">{t('تأكيد كلمة المرور', 'Confirm password')}</Label>
                <Input
                  id="confirm"
                  type={show ? 'text' : 'password'}
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  required
                  minLength={8}
                  autoComplete="new-password"
                  className="h-11"
                  dir="ltr"
                />
              </div>

              <Button type="submit" className="w-full h-11" disabled={stage === 'working'}>
                {stage === 'working' ? (
                  <Loader2 className="h-4 w-4 animate-spin mx-auto" />
                ) : (
                  <>
                    <KeyRound className="h-4 w-4" />
                    {t('تعيين كلمة المرور', 'Reset password')}
                  </>
                )}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
        </div>
      }
    >
      <ResetPasswordInner />
    </Suspense>
  );
}
