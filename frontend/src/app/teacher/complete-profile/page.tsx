'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Loader2, Phone, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PlatformLogo from '@/components/brand/platform-logo';

/**
 * «أكمل بيانات حسابك» — the mandatory teacher profile step.
 *
 * Shown (and enforced server-side) for a teacher whose phone is still missing:
 * mostly Google sign-ups, plus legacy accounts created before the phone became
 * mandatory. Capturing the data here provisions the workspace and starts the
 * free trial exactly once — never a duplicate account or trial.
 */
export default function CompleteTeacherProfilePage() {
  const router = useRouter();
  const { locale } = useLocale();
  const { user, completeProfile } = useAuth();

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [load, setLoad] = useState(false);

  // Prefill from the account (Google already provides the name).
  useEffect(() => {
    if (!user) return;
    setName((prev) => prev || user.name || '');
    setPhone((prev) => prev || user.phone || '');
  }, [user]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) { toast.error(locale === 'ar' ? 'الاسم الكامل مطلوب' : 'Full name is required'); return; }
    if (!phone.trim()) { toast.error(locale === 'ar' ? 'رقم الهاتف مطلوب' : 'Phone number is required'); return; }

    setLoad(true);
    try {
      const { trialStarted } = await completeProfile(name.trim(), phone.trim());
      toast.success(locale === 'ar' ? 'تم حفظ بياناتك وتفعيل حسابك' : 'Your account is ready');
      router.replace(trialStarted ? '/teacher/dashboard' : '/pricing');
    } catch (error: any) {
      toast.error(error?.response?.data?.message || error?.message || (locale === 'ar' ? 'تعذّر حفظ البيانات' : 'Could not save'));
    }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <div className="w-full max-w-md space-y-6 animate-in">
        <div className="text-center">
          <PlatformLogo className="mx-auto mb-5 h-16 w-16 rounded-2xl object-contain shadow-xl" />
          <h1 className="text-2xl font-bold">{locale === 'ar' ? 'أكمل بيانات حسابك' : 'Complete your account'}</h1>
          <p className="text-gray-500 mt-2">
            {locale === 'ar'
              ? 'نحتاج اسمك الكامل ورقم هاتفك لتفعيل منصتك التعليمية.'
              : 'We need your full name and phone number to activate your teaching platform.'}
          </p>
        </div>

        <form onSubmit={submit} className="space-y-4 bg-white p-6 rounded-2xl border shadow-sm">
          <div className="space-y-2">
            <Label>{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label>
            <Input value={name} onChange={(e) => setName(e.target.value)} required className="h-11" />
          </div>
          <div className="space-y-2">
            <Label>{locale === 'ar' ? 'رقم الهاتف' : 'Phone number'}</Label>
            <Input type="tel" inputMode="tel" dir="ltr" required value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+962791234567" className="h-11" />
            <p className="text-xs text-gray-400">{locale === 'ar' ? 'مع رمز الدولة (الأردن +962 افتراضيًا)' : 'Include the country code (Jordan +962 by default)'}</p>
          </div>

          <div className="flex items-start gap-2 rounded-xl bg-teal-50 p-3 text-xs text-teal-700">
            <ShieldCheck className="h-4 w-4 shrink-0 mt-0.5" />
            <span>{locale === 'ar'
              ? 'تُستخدم بياناتك للتواصل معك بشأن منصتك فقط. رقم الهاتف إلزامي لكل حساب معلم.'
              : 'Your data is used only to contact you about your platform. A phone number is mandatory for every teacher account.'}</span>
          </div>

          <Button type="submit" disabled={load} className="w-full h-12 rounded-xl text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #0d9488)' }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" /> : <><Phone className="h-4 w-4 me-2" />{locale === 'ar' ? 'حفظ ومتابعة' : 'Save and continue'}</>}
          </Button>
        </form>
      </div>
    </div>
  );
}
