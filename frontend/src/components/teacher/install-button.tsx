'use client';

import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Download, Share, Smartphone, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { useLocale } from '@/store/locale';
import { resolveLogoUrl } from '@/lib/logo';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

/**
 * "ثبّت التطبيق" — installs the tenant's PWA:
 *  - Android/Chrome: uses the native beforeinstallprompt when available.
 *  - iPhone/iPad: shows the official Add to Home Screen steps (Share →
 *    Add to Home Screen). No unofficial workarounds.
 *  - Desktop without a deferred prompt: shows browser-menu steps.
 */
export default function InstallButton({
  name,
  logoUrl,
  primary = '#2563eb',
  accent = '#38bdf8',
  variant = 'pill',
  className,
}: {
  name: string;
  logoUrl?: string | null;
  primary?: string;
  accent?: string;
  variant?: 'pill' | 'nav';
  className?: string;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const deferred = useRef<BeforeInstallPromptEvent | null>(null);
  const [installed, setInstalled] = useState(false);
  const [isIOS, setIsIOS] = useState(false);
  const [dialog, setDialog] = useState(false);

  useEffect(() => {
    const mq = window.matchMedia('(display-mode: standalone)');
    setInstalled(mq.matches || (navigator as { standalone?: boolean }).standalone === true);

    const ua = navigator.userAgent;
    setIsIOS(
      /iPad|iPhone|iPod/.test(ua) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
    );

    const onPrompt = (e: Event) => {
      e.preventDefault();
      deferred.current = e as BeforeInstallPromptEvent;
    };
    const onInstalled = () => {
      setInstalled(true);
      deferred.current = null;
    };
    const onChange = (e: MediaQueryListEvent) => setInstalled(e.matches);

    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('appinstalled', onInstalled);
    mq.addEventListener('change', onChange);
    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt);
      window.removeEventListener('appinstalled', onInstalled);
      mq.removeEventListener('change', onChange);
    };
  }, []);

  const handleClick = async () => {
    if (deferred.current) {
      await deferred.current.prompt();
      const choice = await deferred.current.userChoice;
      if (choice.outcome === 'accepted') setInstalled(true);
      deferred.current = null;
      return;
    }
    setDialog(true);
  };

  const icon = resolveLogoUrl(logoUrl);

  return (
    <>
      {variant === 'pill' ? (
        <Button
          onClick={handleClick}
          disabled={installed}
          className={`shadow-xl shadow-black/20 hover:scale-105 transition-transform rounded-full px-5 h-13 font-bold text-white ${className || ''}`}
          style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, minWidth: '150px' }}
          aria-label={t('ثبّت التطبيق', 'Install the app')}
        >
          {installed ? (
            <>
              <CheckCircle2 className="h-5 w-5" />
              {t('التطبيق مثبّت', 'App installed')}
            </>
          ) : (
            <>
              <Download className="h-5 w-5" />
              {t('ثبّت التطبيق', 'Install the app')}
            </>
          )}
        </Button>
      ) : (
        <Button
          onClick={handleClick}
          disabled={installed}
          size="sm"
          variant="outline"
          className={`rounded-full font-bold shadow-sm ${className || ''}`}
          style={{ color: primary, borderColor: `${primary}55`, background: `${primary}08` }}
          aria-label={t('ثبّت التطبيق', 'Install the app')}
        >
          {installed ? <CheckCircle2 className="h-4 w-4" /> : <Download className="h-4 w-4" />}
          <span className="hidden lg:inline">
            {installed ? t('مثبّت', 'Installed') : t('ثبّت التطبيق', 'Install the app')}
          </span>
        </Button>
      )}

      <Dialog open={dialog} onOpenChange={setDialog}>
        <DialogContent>
          <DialogHeader className="flex items-start justify-between gap-4">
            <div className="flex items-center gap-3">
              {icon && (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={icon}
                  alt=""
                  className="h-12 w-12 rounded-2xl object-cover ring-1 ring-gray-100"
                />
              )}
              <div>
                <DialogTitle>{t('تثبيت التطبيق', 'Install the app')}</DialogTitle>
                <p className="text-sm text-gray-500 mt-1">{name}</p>
              </div>
            </div>
            <button
              onClick={() => setDialog(false)}
              className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200"
              aria-label={t('إغلاق', 'Close')}
            >
              <X className="h-4 w-4" />
            </button>
          </DialogHeader>

          {isIOS ? (
            <div className="space-y-4">
              <div className="flex items-start gap-3 rounded-2xl border border-gray-100 p-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                  <Share className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-bold text-sm">{t('الخطوة 1: اضغط زر المشاركة', 'Step 1: Tap the Share button')}</p>
                  <p className="text-xs text-gray-500 mt-1" dir="ltr">{t('أيقونة المربع مع السهم لأعلى في شريط Safari السفلي', 'The square-with-up-arrow icon in the Safari toolbar')}</p>
                </div>
              </div>
              <div className="flex items-start gap-3 rounded-2xl border border-gray-100 p-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                  <Smartphone className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-bold text-sm">{t('الخطوة 2: اختر "إضافة إلى الشاشة الرئيسية"', 'Step 2: Choose "Add to Home Screen"')}</p>
                  <p className="text-xs text-gray-500 mt-1">{t('من قائمة المشاركة، مرر للأعلى إن لزم', 'From the share sheet — scroll up if needed')}</p>
                </div>
              </div>
              <div className="flex items-start gap-3 rounded-2xl border border-gray-100 p-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                  <CheckCircle2 className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-bold text-sm">{t('الخطوة 3: اضغط "إضافة"', 'Step 3: Tap "Add"')}</p>
                  <p className="text-xs text-gray-500 mt-1">{t('سيظهر التطبيق على شاشتك الرئيسية كأيقونة منفصلة', 'The app will appear on your home screen with its own icon')}</p>
                </div>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="flex items-start gap-3 rounded-2xl border border-gray-100 p-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                  <Download className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-bold text-sm">{t('من قائمة المتصفح اختر "تثبيت التطبيق"', 'From the browser menu choose "Install app"')}</p>
                  <p className="text-xs text-gray-500 mt-1">
                    {t('Chrome/Edge: أيقونة التثبيت في شريط العنوان، أو القائمة ⋮ ثم "تثبيت التطبيق"', 'Chrome/Edge: the install icon in the address bar, or the ⋮ menu → "Install app"')}
                  </p>
                </div>
              </div>
              <div className="flex items-start gap-3 rounded-2xl border border-gray-100 p-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                  <Smartphone className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-bold text-sm">{t('أو أضفه من قائمة المشاركة على الجوال', 'Or add it from the share menu on mobile')}</p>
                  <p className="text-xs text-gray-500 mt-1">{t('شارك ← إضافة إلى الشاشة الرئيسية', 'Share → Add to Home Screen')}</p>
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button onClick={() => setDialog(false)}>{t('حسنًا', 'Got it')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}