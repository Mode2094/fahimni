'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { getDeviceId } from '@/lib/device';
import { pushTokens } from '@/services/endpoints';
import {
  currentPushEndpoint,
  fcmTokenFromEndpoint,
  pushConfigured,
  pushSupported,
  subscribeForPush,
  unsubscribeFromPush,
} from '@/lib/push';
import { isIosDevice, isStandalone } from '@/lib/pwa';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CheckCircle2, Download, Share, Smartphone, X } from 'lucide-react';
import { toast } from 'sonner';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

type PushState = 'loading' | 'unsupported' | 'unconfigured' | 'denied' | 'off' | 'on';

/** Module-scoped holder so the card owns exactly one beforeinstallprompt. */
let pendingPrompt: BeforeInstallPromptEvent | null = null;

/**
 * "تطبيق فهمني" — the student's account controls show how to install the app on
 * this device, and let them turn web-push notifications on/off here. Both
 * features degrade honestly: if the build has no push key, or the browser
 * cannot install/push, we say so instead of faking it.
 */
export default function AppCard() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [installed, setInstalled] = useState(true);
  const [ios, setIos] = useState(false);
  const [canPrompt, setCanPrompt] = useState(false);
  const [showIosSteps, setShowIosSteps] = useState(false);

  const [push, setPush] = useState<PushState>('loading');
  const [busy, setBusy] = useState(false);

  /* ── Install detection ─────────────────────────────────────────────── */
  useEffect(() => {
    setInstalled(isStandalone());
    setIos(isIosDevice(navigator.userAgent, navigator.maxTouchPoints || 0, navigator.platform || ''));

    const onPrompt = (e: Event) => {
      e.preventDefault();
      pendingPrompt = e as BeforeInstallPromptEvent;
      setCanPrompt(true);
    };
    const onInstalled = () => {
      setInstalled(true);
      setCanPrompt(false);
      pendingPrompt = null;
    };

    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('appinstalled', onInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, []);

  /* ── Push state ────────────────────────────────────────────────────── */
  useEffect(() => {
    let alive = true;

    (async () => {
      if (!pushSupported()) {
        if (alive) setPush('unsupported');
        return;
      }
      if (!pushConfigured()) {
        if (alive) setPush('unconfigured');
        return;
      }
      if (Notification.permission === 'denied') {
        if (alive) setPush('denied');
        return;
      }

      const endpoint = await currentPushEndpoint();
      if (!alive) return;
      setPush(endpoint ? 'on' : 'off');

      // Keep the server token fresh for a device that is already subscribed.
      if (endpoint) {
        const token = fcmTokenFromEndpoint(endpoint);
        if (token) {
          pushTokens.register({ token, platform: 'web', device_id: getDeviceId() }).catch(() => {});
        }
      }
    })();

    return () => {
      alive = false;
    };
  }, []);

  const enablePush = useCallback(async () => {
    setBusy(true);
    try {
      const result = await subscribeForPush();
      if (!result) {
        const denied = typeof Notification !== 'undefined' && Notification.permission === 'denied';
        setPush(denied ? 'denied' : pushConfigured() ? 'off' : 'unconfigured');
        toast.error(
          denied
            ? t('تم رفض إذن الإشعارات — فعّله من إعدادات المتصفح', 'Notification permission was denied — enable it in browser settings')
            : t('تعذّر تفعيل الإشعارات على هذا الجهاز', 'Could not enable notifications on this device'),
        );
        return;
      }

      await pushTokens.register({ token: result.token, platform: 'web', device_id: getDeviceId() });
      setPush('on');
      toast.success(t('تم تفعيل الإشعارات على هذا الجهاز', 'Notifications enabled on this device'));
    } catch {
      toast.error(t('تعذّر تفعيل الإشعارات', 'Could not enable notifications'));
    } finally {
      setBusy(false);
    }
  }, [locale]);

  const disablePush = useCallback(async () => {
    setBusy(true);
    try {
      const token = await unsubscribeFromPush();
      if (token) await pushTokens.remove(token).catch(() => {});
      setPush('off');
      toast.success(t('تم إيقاف الإشعارات على هذا الجهاز', 'Notifications disabled on this device'));
    } catch {
      toast.error(t('تعذّر إيقاف الإشعارات', 'Could not disable notifications'));
    } finally {
      setBusy(false);
    }
  }, [locale]);

  const install = async () => {
    if (pendingPrompt) {
      await pendingPrompt.prompt();
      const choice = await pendingPrompt.userChoice;
      if (choice.outcome === 'accepted') setInstalled(true);
      pendingPrompt = null;
      setCanPrompt(false);
      return;
    }
    // iOS (and browsers without a native prompt) get the manual steps.
    setShowIosSteps(true);
  };

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-5">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-50 text-teal-600">
            <Smartphone className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h3 className="font-bold leading-tight">{t('تطبيق فهمني على جهازك', 'Fahimni on your device')}</h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t('تثبيت التطبيق وتفعيل الإشعارات', 'Install the app and enable notifications')}
            </p>
          </div>
        </div>

        {/* Install */}
        <div className="mt-4 flex items-center justify-between gap-3 rounded-2xl border p-3">
          <div className="min-w-0">
            <p className="text-sm font-medium">{t('تثبيت التطبيق', 'Install the app')}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {installed
                ? t('التطبيق مثبّت على هذا الجهاز', 'Installed on this device')
                : ios
                  ? t('عبر Safari: مشاركة ← إضافة إلى الشاشة الرئيسية', 'In Safari: Share → Add to Home Screen')
                  : t('افتح فهمني كتطبيق من شاشتك الرئيسية', 'Open Fahimni as an app from your home screen')}
            </p>
          </div>
          {installed ? (
            <Badge className="shrink-0 gap-1 border-0 bg-emerald-100 text-emerald-700">
              <CheckCircle2 className="h-3.5 w-3.5" />
              {t('مثبّت', 'Installed')}
            </Badge>
          ) : (
            <Button size="sm" className="shrink-0 gap-1 rounded-full" onClick={install}>
              <Download className="h-3.5 w-3.5" />
              {t('تثبيت', 'Install')}
            </Button>
          )}
        </div>

        {/* Notifications */}
        <div className="mt-3 flex items-center justify-between gap-3 rounded-2xl border p-3">
          <div className="min-w-0">
            <p className="text-sm font-medium">{t('إشعارات هذا الجهاز', 'Notifications on this device')}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">{pushHint(push, t)}</p>
          </div>
          <Switch
            label={t('إشعارات هذا الجهاز', 'Device notifications')}
            checked={push === 'on'}
            disabled={busy || push === 'loading' || push === 'unsupported' || push === 'unconfigured' || push === 'denied'}
            onCheckedChange={(next) => (next ? enablePush() : disablePush())}
          />
        </div>
      </CardContent>

      <Dialog open={showIosSteps} onOpenChange={setShowIosSteps}>
        <DialogContent>
          <DialogHeader className="flex items-start justify-between gap-4">
            <DialogTitle>{t('إضافة فهمني إلى الشاشة الرئيسية', 'Add Fahimni to your home screen')}</DialogTitle>
            <button
              type="button"
              onClick={() => setShowIosSteps(false)}
              aria-label={t('إغلاق', 'Close')}
              className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"
            >
              <X className="h-4 w-4" />
            </button>
          </DialogHeader>

          <div className="space-y-3">
            <Step icon={Share} title={t('اضغط زر المشاركة', 'Tap the Share button')} text={t('المربع مع السهم لأعلى في شريط Safari', 'The square-with-arrow in the Safari toolbar')} />
            <Step icon={Smartphone} title={t('اختر «إضافة إلى الشاشة الرئيسية»', 'Choose "Add to Home Screen"')} text={t('مرّر للأعلى في قائمة المشاركة إن لزم', 'Scroll up in the share sheet if needed')} />
            <Step icon={Download} title={t('اضغط «إضافة»', 'Tap "Add"')} text={t('سيظهر التطبيق كأيقونة مستقلة', 'The app appears as its own icon')} />
          </div>

          <DialogFooter>
            <Button onClick={() => setShowIosSteps(false)}>{t('حسنًا', 'Got it')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function pushHint(state: PushState, t: (ar: string, en: string) => string): string {
  switch (state) {
    case 'on':
      return t('مفعّلة — ستصلك إشعارات الدروس والاختبارات', 'On — you will get lesson and exam alerts');
    case 'denied':
      return t('مرفوضة من المتصفح — فعّلها من إعدادات الموقع', 'Blocked by the browser — enable it in site settings');
    case 'unsupported':
      return t('جهازك/متصفحك لا يدعم إشعارات الويب', 'Your browser does not support web notifications');
    case 'unconfigured':
      return t('الإشعارات غير مهيّأة على هذه المنصة حالياً', 'Notifications are not configured on this platform yet');
    case 'loading':
      return t('جارٍ التحقّق…', 'Checking…');
    default:
      return t('لا تصلك إشعارات على هذا الجهاز بعد', 'You are not receiving notifications on this device yet');
  }
}

function Step({ icon: Icon, title, text }: { icon: typeof Share; title: string; text: string }) {
  return (
    <div className="flex items-start gap-3 rounded-2xl border border-slate-100 p-4">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
        <Icon className="h-5 w-5" />
      </span>
      <div>
        <p className="text-sm font-bold">{title}</p>
        <p className="mt-1 text-xs text-muted-foreground">{text}</p>
      </div>
    </div>
  );
}
