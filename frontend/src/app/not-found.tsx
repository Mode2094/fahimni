'use client';
import { Button } from '@/components/ui/button';
import { Home, ArrowLeft } from 'lucide-react';
import { useLocale } from '@/store/locale';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import dynamic from 'next/dynamic';

const Lottie = dynamic(() => import('lottie-react'), { ssr: false });

export default function NotFound() {
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-b from-teal-50/60 to-white">
      <div className="w-full max-w-md p-8 text-center space-y-4 bg-card text-card-foreground rounded-3xl border shadow-lg">
        <div className="mx-auto w-64 sm:w-72">
          <Lottie animationData={require('@/assets/404-cat.json')} loop autoplay className="w-full h-auto" />
        </div>
        <div className="space-y-1">
          <h1 className="text-4xl font-extrabold text-teal-800">404</h1>
          <p className="text-sm text-muted-foreground">{t('الصفحة التي تبحث عنها غير موجودة — يبدو أن القطة أكلتها!', 'The page you are looking for does not exist — looks like the cat ate it!')}</p>
        </div>
        <div className="flex justify-center gap-3 pt-1">
          <button onClick={() => router.back()} className="inline-flex items-center gap-2 rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
            <ArrowLeft className="h-4 w-4" /> {t('رجوع', 'Go back')}
          </button>
          <Link href="/">
            <Button className="gap-2 rounded-full"><Home className="h-4 w-4" />{t('العودة للرئيسية', 'Back to home')}</Button>
          </Link>
        </div>
      </div>
    </div>
  );
}
