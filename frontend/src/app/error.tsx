'use client';
import { Button } from '@/components/ui/button';
import { AlertTriangle, RefreshCw, Home } from 'lucide-react';
import { useLocale } from '@/store/locale';
import Link from 'next/link';

export default function Error({ reset }: { error: Error; reset: () => void }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-background">
      <div className="w-full max-w-md p-8 text-center space-y-6 bg-card text-card-foreground rounded-2xl border shadow-sm">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50"><AlertTriangle className="h-8 w-8 text-red-500" /></div>
        <h1 className="text-2xl font-bold">{t('خطأ غير متوقع', 'Something went wrong')}</h1>
        <p className="text-sm text-muted-foreground">{t('حدث خطأ أثناء عرض هذه الصفحة. حاول إعادة التحميل أو العودة للرئيسية.', 'An error occurred while loading this page. Try reloading or go back home.')}</p>
        <div className="flex flex-col sm:flex-row gap-2">
          <Button onClick={reset} className="gap-2 rounded-full flex-1"><RefreshCw className="h-4 w-4" /> {t('إعادة المحاولة', 'Try again')}</Button>
          <Link href="/" className="flex-1"><Button variant="outline" className="gap-2 rounded-full w-full"><Home className="h-4 w-4" /> {t('الرئيسية', 'Home')}</Button></Link>
        </div>
      </div>
    </div>
  );
}
