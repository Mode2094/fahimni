'use client';

import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from 'react';
import Hls from 'hls.js';
import { ShieldAlert, Loader2, Maximize2, Minimize2 } from 'lucide-react';
import { getDeviceId } from '@/lib/device';
import { canResume, shouldPersist, formatClock } from '@/lib/watch-position';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * واجهة تحكّم مكشوفة للمشغّل المحمي: تتيح للملاحظات قراءة الزمن الحالي
 * والقفز إلى لحظة محفوظة. القفز يستخدم عنصر `<video>` الموجود نفسه — لا نتجاوز
 * الحماية ولا نجلب أي نسخة من الفيديو.
 */
export type ProtectedVideoHandle = {
  /** الزمن الحالي بالثواني، أو null إن لم يصبح المشغّل جاهزاً بعد. */
  getCurrentTime: () => number | null;
  /** مدة الفيديو بالثواني، أو null إن لم تُعرف بعد. */
  getDuration: () => number | null;
  /** يقفز إلى الثواني المطلوبة ويبدأ التشغيل؛ يعيد هل نجح القفز. */
  seekTo: (seconds: number) => boolean;
};

export type ProtectedVideoPlayerProps = {
  videoId: string;
};

/** Extract the 11-char video id from any YouTube URL (watch, youtu.be, shorts, embed). */
function youtubeId(input: string): string {
  const s = String(input || '').trim();
  const patterns = [
    /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/))([\w-]{6,})/,
    /(?:youtu\.be\/)([\w-]{6,})/,
  ];
  for (const p of patterns) {
    const m = s.match(p);
    if (m?.[1]) return m[1];
  }
  // Already a bare id?
  if (/^[\w-]{11}$/.test(s)) return s;
  return s;
}

type Playback = {
  type: 'stream' | 'provider' | 'external';
  status?: 'processing' | 'ready' | 'error';
  title?: string;
  mime_type?: string;
  stream_url?: string;
  expires_in?: number;
  provider?: string;
  provider_video_id?: string;
  watermark?: {
    enabled: boolean;
    text?: string;
    opacity?: number;
    speed?: 'slow' | 'medium' | 'fast';
    size?: string;
  };
  protection?: {
    allow_download?: boolean;
    watermark_enabled?: boolean;
    pause_on_hidden?: boolean;
    block_picture_in_picture?: boolean;
  };
  /** آخر موضع مشاهدة للطالب نفسه (استكمال من حيث توقف) — يُهمَل عند المنتهي. */
  resume?: { position_seconds: number; duration_seconds: number; updated_at?: string | null } | null;
};

/* حفظ دوري خفيف لموضع المشاهدة + حفظ عند الإيقاف/الخروج. */
const POSITION_PING_MS = 10_000;

/* مواقع العلامة المائية — تتنقل بينها ببطء وسلاسة */
const WATERMARK_POSITIONS = [
  { top: '3%', left: '3%' },     // أعلى يسار
  { top: '3%', left: '70%' },    // أعلى يمين
  { top: '86%', left: '70%' },   // أسفل يمين
  { top: '86%', left: '3%' },    // أسفل يسار
  { top: '46%', left: '66%' },   // منتصف يمين
  { top: '46%', left: '22%' },   // منتصف يسار
];

const SPEED_CONFIG: Record<string, { interval: number; transition: number }> = {
  slow: { interval: 9000, transition: 4200 },
  medium: { interval: 6000, transition: 3000 },
  fast: { interval: 3500, transition: 2000 },
};

const ProtectedVideoPlayer = forwardRef<ProtectedVideoHandle, ProtectedVideoPlayerProps>(
function ProtectedVideoPlayer({ videoId }, ref) {
  const [playback, setPlayback] = useState<Playback | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [blurred, setBlurred] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [wmPos, setWmPos] = useState(0);
  const hlsRef = useRef<Hls | null>(null);
  // المصدر المُنتظَر: في التحميل الأول لا يكون عنصر <video> موجودًا بعد (تظهر
  // شاشة التحميل)، فنتذكّر المصدر ونربطه بعد أن يُركَّب العنصر في الـ DOM.
  const pendingSource = useRef<{ url: string; kind: Playback['type']; preserve: boolean; startAt?: number } | null>(null);
  const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const wmTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const posTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  // الاستكمال: الموضع الذي استُكمل منه + ما إذا اختار الطالب البدء من البداية.
  const [resumedFrom, setResumedFrom] = useState<number | null>(null);
  const [startOver, setStartOver] = useState(false);
  const startOverRef = useRef(false);
  const lastSavedRef = useRef<number>(0);

  /* ملء الشاشة الحقيقي بالفيديو */
  useEffect(() => {
    const onFs = () => setIsFullscreen(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onFs);
    return () => document.removeEventListener('fullscreenchange', onFs);
  }, []);

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    } else {
      containerRef.current?.requestFullscreen().catch(() => {});
    }
  };

  /* حركة العلامة المائية — تنتقل ببطء بين المناطق الست */
  useEffect(() => {
    const wm = playback?.watermark;
    if (!wm?.enabled) return;
    const cfg = SPEED_CONFIG[wm.speed ?? 'slow'] ?? SPEED_CONFIG.slow;
    wmTimer.current = setInterval(() => setWmPos((p) => (p + 1) % WATERMARK_POSITIONS.length), cfg.interval);
    return () => { if (wmTimer.current) clearInterval(wmTimer.current); };
  }, [playback?.watermark?.enabled, playback?.watermark?.speed]);

  /* إخفاء المحتوى عند مغادرة الصفحة (حماية من التصوير بالتقاط الشاشة) */
  useEffect(() => {
    const hide = () => setBlurred(true);
    const show = () => setBlurred(false);
    const onVisibility = () => {
      if (document.hidden) { hide(); videoRef.current?.pause(); } else { show(); }
    };
    const onBlur = () => { hide(); videoRef.current?.pause(); };
    const onFocus = () => show();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    window.addEventListener('focus', onFocus);
    return () => {
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('blur', onBlur);
      window.removeEventListener('focus', onFocus);
    };
  }, []);

  const destroyHls = useCallback(() => {
    if (hlsRef.current) {
      hlsRef.current.destroy();
      hlsRef.current = null;
    }
  }, []);

  const attachSource = useCallback((url: string, kind: Playback['type'], preserveTime: boolean, startAt?: number) => {
    const v = videoRef.current;
    if (!v) return;

    const prevTime = preserveTime ? v.currentTime : undefined;
    const wasPlaying = preserveTime ? !v.paused : false;
    // عند التحميل الأول من نقطة استئناف: نقفز للموضع فور جهوزية البيانات
    // الوصفية دون تشغيل تلقائي (يواصل الطالب بنفسه).
    const initialTime = !preserveTime && startAt && startAt > 0 ? startAt : undefined;
    const seek = (time: number | undefined, play: boolean) => {
      if (time !== undefined) v.currentTime = time;
      if (play) v.play().catch(() => {});
    };
    const restore = () => seek(preserveTime ? prevTime : initialTime, preserveTime ? wasPlaying : false);

    destroyHls();

    // الفيديوهات المحلية (type=stream = MP4 عبر وسيط النظام) تُشغَّل مباشرة؛
    // أما HLS (provider=بني/كلاودفلير) فيُشغَّل عبر hls.js أو native على Safari.
    if (kind === 'stream') {
      v.src = url;
      if (preserveTime || initialTime !== undefined) {
        v.addEventListener('loadedmetadata', restore, { once: true });
      }
      return;
    }

    if (Hls.isSupported()) {
      const hls = new Hls({ enableWorker: true, maxBufferLength: 30 });
      hlsRef.current = hls;
      hls.loadSource(url);
      hls.attachMedia(v);
      hls.on(Hls.Events.ERROR, (_evt, data) => {
        if (data.fatal) {
          setError('error');
          destroyHls();
        }
      });
      if (preserveTime || initialTime !== undefined) {
        hls.on(Hls.Events.MANIFEST_PARSED, restore);
      }
    } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
      v.src = url;
      if (preserveTime || initialTime !== undefined) {
        v.addEventListener('loadedmetadata', restore, { once: true });
      }
    } else {
      setError('error');
    }
  }, [destroyHls]);

  // يُربط المصدر بعد أن يصبح عنصر <video> مركَّبًا فعلاً في الصفحة.
  const applyPendingSource = useCallback(() => {
    const pending = pendingSource.current;
    if (!pending || !videoRef.current) return;
    pendingSource.current = null;
    attachSource(pending.url, pending.kind, pending.preserve, pending.startAt);
  }, [attachSource]);

  const loadPlayback = useCallback(async (preserveTime: boolean) => {
    const tid = localStorage.getItem('tenant_id');
    const deviceId = getDeviceId();

    try {
      const res = await fetch(`${API}/videos/${videoId}/playback`, {
        headers: {
          Accept: 'application/json',
          ...(tid ? { 'X-Tenant-Id': tid } : {}),
          'X-Device-Id': deviceId,
        },
      });
      if (!res.ok) {
        const d = await res.json().catch(() => null);
        setError(d?.code === 'DEVICE_LIMIT_REACHED' ? 'device_limit' : res.status === 403 ? 'access_denied' : 'error');
        return;
      }
      const data = await res.json();
      const p: Playback = data.playback;
      setPlayback(p);
      setError(null);

      if (p.status === 'processing') {
        refreshTimer.current = setTimeout(() => loadPlayback(false), 10_000);
        return;
      }

      if (p.stream_url) {
        // الاستكمال: عند التحميل الأول فقط، ومن نقطة استئناف سارية (ولم يُختر
        // البدء من البداية)، نقفز إلى آخر موضع محفوظ للطالب نفسه.
        const resumeAt = !preserveTime && !startOverRef.current && canResume(p.resume)
          ? Math.floor(Number(p.resume?.position_seconds ?? 0))
          : 0;
        pendingSource.current = {
          url: p.stream_url,
          kind: p.type,
          preserve: preserveTime,
          startAt: resumeAt > 0 ? resumeAt : undefined,
        };
        if (resumeAt > 0 && !preserveTime) setResumedFrom(resumeAt);
      }

      if (p.expires_in && p.expires_in > 0) {
        if (refreshTimer.current) clearTimeout(refreshTimer.current);
        refreshTimer.current = setTimeout(() => loadPlayback(true), Math.max(30, p.expires_in - 60) * 1000);
      }
    } catch {
      setError('error');
    }
  }, [videoId, attachSource]);

  // حفظ موضع المشاهدة (نبضة خفيفة): لا يرمي ولا يعطّل التشغيل عند الفشل.
  const savePosition = useCallback((useKeepalive = false) => {
    const v = videoRef.current;
    if (!v || !isFinite(v.currentTime)) return;
    const position = Math.floor(v.currentTime);
    const duration = isFinite(v.duration) ? Math.floor(v.duration) : 0;
    // لا نبضة بلا تقدّم فعلي (تخفيف الطلبات).
    if (!shouldPersist(position, lastSavedRef.current)) return;
    lastSavedRef.current = position;

    const tid = localStorage.getItem('tenant_id');
    try {
      fetch(`${API}/videos/${videoId}/position`, {
        method: 'POST',
        keepalive: useKeepalive,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(tid ? { 'X-Tenant-Id': tid } : {}),
        },
        body: JSON.stringify({ position_seconds: position, duration_seconds: duration }),
      }).catch(() => {});
    } catch {
      /* الفشل لا يجب أن يوقف التشغيل */
    }
  }, [videoId]);

  // نبضة دورية أثناء التشغيل + حفظ عند الإيقاف/الانتهاء/مغادرة الصفحة.
  useEffect(() => {
    const onPause = () => savePosition(false);
    const onEnded = () => savePosition(false);
    const onHide = () => { if (document.hidden) savePosition(true); };
    const onPageHide = () => savePosition(true);

    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('pagehide', onPageHide);

    posTimer.current = setInterval(() => {
      const v = videoRef.current;
      if (v && !v.paused && !v.ended) savePosition(false);
    }, POSITION_PING_MS);

    const v = videoRef.current;
    v?.addEventListener('pause', onPause);
    v?.addEventListener('ended', onEnded);

    return () => {
      document.removeEventListener('visibilitychange', onHide);
      window.removeEventListener('pagehide', onPageHide);
      v?.removeEventListener('pause', onPause);
      v?.removeEventListener('ended', onEnded);
      if (posTimer.current) clearInterval(posTimer.current);
      savePosition(true);
    };
  }, [savePosition, playback]);

  useEffect(() => {
    startOverRef.current = startOver;
  }, [startOver]);

  useEffect(() => {
    loadPlayback(false);
    return () => {
      if (refreshTimer.current) clearTimeout(refreshTimer.current);
      if (wmTimer.current) clearInterval(wmTimer.current);
      destroyHls();
    };
  }, [loadPlayback, destroyHls]);

  // اربط المصدر بعد كل تغيّر في حالة التشغيل، أي بعد تركيب عنصر <video>.
  useEffect(() => {
    applyPendingSource();
  }, [playback, applyPendingSource]);

  // واجهة التحكّم المكشوفة للملاحظات: قراءة الزمن الحالي والقفز للّحظات المحفوظة.
  useImperativeHandle(ref, () => ({
    getCurrentTime: () => {
      const v = videoRef.current;
      if (!v || v.readyState < 1 || !isFinite(v.currentTime)) return null;
      return v.currentTime;
    },
    getDuration: () => {
      const v = videoRef.current;
      if (!v || !isFinite(v.duration) || v.duration <= 0) return null;
      return v.duration;
    },
    seekTo: (seconds: number) => {
      const v = videoRef.current;
      if (!v || !Number.isFinite(seconds) || seconds < 0) return false;
      const dur = isFinite(v.duration) && v.duration > 0 ? v.duration : 0;
      // لا نقفز إلى ما بعد نهاية الفيديو نفسه.
      const target = dur > 0 ? Math.min(seconds, Math.max(0, dur - 0.5)) : seconds;
      try {
        v.currentTime = target;
      } catch {
        return false;
      }
      v.play().catch(() => {});
      return true;
    },
  }), []);

  const protection = playback?.protection ?? {};
  const blockPip = protection.block_picture_in_picture ?? true;
  const pauseOnHidden = protection.pause_on_hidden ?? true;

  useEffect(() => {
    if (!pauseOnHidden) return;
    const onVisibility = () => {
      if (document.hidden) videoRef.current?.pause();
    };
    const onBlur = () => videoRef.current?.pause();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    return () => {
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('blur', onBlur);
    };
  }, [pauseOnHidden]);

  const watermark = playback?.watermark;
  const wmText = watermark?.enabled ? watermark.text : null;
  const wmCfg = SPEED_CONFIG[watermark?.speed ?? 'slow'] ?? SPEED_CONFIG.slow;
  const wmOpacity = watermark?.opacity ?? 0.35;
  const wmFontSize = watermark?.size ?? '1rem';

  const prevent = (e: React.SyntheticEvent) => e.preventDefault();

  if (error === 'access_denied') {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <div className="text-center text-white/70 space-y-3">
          <ShieldAlert className="h-14 w-14 mx-auto" />
          <p>هذا الفيديو محمي — يجب تسجيل الدخول والمشاركة بالكورس للمشاهدة</p>
        </div>
      </div>
    );
  }

  if (error === 'device_limit') {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <div className="text-center text-white/70 space-y-3">
          <ShieldAlert className="h-14 w-14 mx-auto" />
          <p>وصلت الحد المسموح للأجهزة — تواصل مع معلمك لإعادة تعيين أجهزتك</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <div className="text-center text-white/70 space-y-3">
          <ShieldAlert className="h-14 w-14 mx-auto" />
          <p>تعذر تحميل الفيديو. حاول مرة أخرى.</p>
        </div>
      </div>
    );
  }

  if (!playback) {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <Loader2 className="h-10 w-10 animate-spin text-white/60" />
      </div>
    );
  }

  if (playback.status === 'processing') {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <div className="text-center text-white/70 space-y-3">
          <Loader2 className="h-10 w-10 animate-spin mx-auto text-white/60" />
          <p>جاري معالجة الفيديو وتحويله للبث عالي الجودة…</p>
          <p className="text-xs text-white/40">سيبدأ التشغيل تلقائيًا عند اكتمال المعالجة</p>
        </div>
      </div>
    );
  }

  if (playback.status === 'error') {
    return (
      <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center">
        <div className="text-center text-white/70 space-y-3">
          <ShieldAlert className="h-14 w-14 mx-auto" />
          <p>فشلت معالجة الفيديو — يرجى إعادة رفعه أو التواصل مع الدعم</p>
        </div>
      </div>
    );
  }

  if (playback.type === 'external' && playback.provider === 'youtube') {
    return (
      <div className="aspect-video rounded-2xl overflow-hidden bg-black" onContextMenu={prevent}>
        <iframe
          className="w-full h-full"
          src={`https://www.youtube.com/embed/${youtubeId(playback.provider_video_id || '')}`}
          title={playback.title || 'video'}
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      </div>
    );
  }

  if (playback.type === 'external' && playback.provider === 'vimeo') {
    return (
      <div className="aspect-video rounded-2xl overflow-hidden bg-black" onContextMenu={prevent}>
        <iframe
          className="w-full h-full"
          src={`https://player.vimeo.com/video/${playback.provider_video_id}`}
          title={playback.title || 'video'}
          allow="autoplay; fullscreen; picture-in-picture"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      </div>
    );
  }

  if (playback.type === 'external' && playback.provider === 'youtube') {
    return (
      <div className="aspect-video rounded-2xl overflow-hidden bg-black" onContextMenu={prevent}>
        <iframe
          className="w-full h-full"
          src={`https://www.youtube.com/embed/${youtubeId(playback.provider_video_id || '')}`}
          title={playback.title || 'video'}
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      </div>
    );
  }

  if (playback.type === 'external' && playback.provider === 'vimeo') {
    return (
      <div className="aspect-video rounded-2xl overflow-hidden bg-black" onContextMenu={prevent}>
        <iframe
          className="w-full h-full"
          src={`https://player.vimeo.com/video/${playback.provider_video_id}`}
          title={playback.title || 'video'}
          allow="autoplay; fullscreen; picture-in-picture"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      </div>
    );
  }

  const pos = WATERMARK_POSITIONS[wmPos];

  return (
    <div
      ref={containerRef}
      className={`relative bg-black rounded-2xl overflow-hidden select-none ${isFullscreen ? 'w-full h-full rounded-none bg-black' : 'aspect-video'}`}
      onContextMenu={prevent}
      onDragStart={prevent}
    >
      <video
        ref={videoRef}
        className={`w-full h-full transition-[filter] duration-300 ${blurred ? 'blur-xl opacity-40' : ''}`}
        controls
        playsInline
        controlsList="nodownload noremoteplayback"
        disablePictureInPicture={blockPip}
        disableRemotePlayback
        onContextMenu={prevent}
      />

      {/* غطاء إخفاء عند مغادرة الصفحة */}
      {blurred && (
        <div className="absolute inset-0 z-20 flex items-center justify-center bg-black/70 backdrop-blur-sm">
          <div className="text-center text-white/80 space-y-2 px-6">
            <ShieldAlert className="h-10 w-10 mx-auto opacity-70" />
            <p className="text-sm font-medium">المحتوى مخفي أثناء مغادرة الصفحة</p>
            <p className="text-xs text-white/50">عد إلى التبويب لاستئناف المشاهدة</p>
          </div>
        </div>
      )}

      {/* زر ملء الشاشة */}
      <button
        onClick={toggleFullscreen}
        className="absolute top-3 start-3 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-white/90 backdrop-blur transition-all hover:bg-black/75 hover:scale-105"
        title={isFullscreen ? 'خروج من ملء الشاشة' : 'ملء الشاشة'}
        aria-label={isFullscreen ? 'خروج من ملء الشاشة' : 'ملء الشاشة'}
      >
        {isFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
      </button>

      {/* العلامة المائية الديناميكية — اسم الطالب يتحرك ببطء بين المناطق */}
      {wmText && !blurred && (
        <div
          aria-hidden
          className="pointer-events-none absolute z-10 select-none whitespace-nowrap"
          style={{
            top: pos.top,
            left: pos.left,
            opacity: wmOpacity,
            fontSize: wmFontSize,
            color: '#fff',
            textShadow: '0 1px 3px rgba(0,0,0,0.5)',
            transition: `top ${wmCfg.transition}ms cubic-bezier(0.16,1,0.3,1), left ${wmCfg.transition}ms cubic-bezier(0.16,1,0.3,1), opacity 600ms ease`,
            fontWeight: 600,
            letterSpacing: '0.02em',
          }}
        >
          {wmText}
        </div>
      )}

      {/* شريط الاستكمال: يعرض من أين تمت المتابعة، مع خيار البدء من البداية */}
      {resumedFrom !== null && (
        <div className="absolute top-3 start-1/2 -translate-x-1/2 z-10 flex items-center gap-2 rounded-full bg-black/75 px-3 py-1.5 text-[11px] text-white/90 backdrop-blur">
          <span>تم الاستكمال من {formatClock(resumedFrom)}</span>
          <button
            type="button"
            onClick={() => {
              setStartOver(true);
              setResumedFrom(null);
              const v = videoRef.current;
              if (v) {
                v.currentTime = 0;
                lastSavedRef.current = 0;
                v.play().catch(() => {});
              }
            }}
            className="rounded-full bg-white/15 px-2 py-0.5 font-medium transition-colors hover:bg-white/25"
          >
            البدء من البداية
          </button>
          <button
            type="button"
            onClick={() => setResumedFrom(null)}
            aria-label="إخفاء"
            className="text-white/60 transition-colors hover:text-white"
          >
            ✕
          </button>
        </div>
      )}

      <div className="absolute top-2 end-2 px-2 py-0.5 rounded bg-black/50 text-[10px] text-white/70 pointer-events-none">
        محتوى محمي — يحظر التنزيل أو التصوير
      </div>
    </div>
  );
});

export default ProtectedVideoPlayer;
