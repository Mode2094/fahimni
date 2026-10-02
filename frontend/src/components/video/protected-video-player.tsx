'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Hls from 'hls.js';
import { ShieldAlert, Loader2, Maximize2, Minimize2 } from 'lucide-react';
import { getDeviceId } from '@/lib/device';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

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
};

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

export default function ProtectedVideoPlayer({ videoId }: { videoId: string }) {
  const [playback, setPlayback] = useState<Playback | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [blurred, setBlurred] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [wmPos, setWmPos] = useState(0);
  const hlsRef = useRef<Hls | null>(null);
  const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const wmTimer = useRef<ReturnType<typeof setInterval> | null>(null);

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

  const attachSource = useCallback((url: string, kind: Playback['type'], preserveTime: boolean) => {
    const v = videoRef.current;
    if (!v) return;

    const prevTime = preserveTime ? v.currentTime : undefined;
    const wasPlaying = preserveTime ? !v.paused : false;
    const seek = (time: number | undefined, play: boolean) => {
      if (time !== undefined) v.currentTime = time;
      if (play) v.play().catch(() => {});
    };

    destroyHls();

    // الفيديوهات المحلية (type=stream = MP4 عبر وسيط النظام) تُشغَّل مباشرة؛
    // أما HLS (provider=بني/كلاودفلير) فيُشغَّل عبر hls.js أو native على Safari.
    if (kind === 'stream') {
      v.src = url;
      if (preserveTime) {
        const onMeta = () => seek(prevTime, wasPlaying);
        v.addEventListener('loadedmetadata', onMeta, { once: true });
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
      if (preserveTime) {
        const onManifest = () => seek(prevTime, wasPlaying);
        hls.on(Hls.Events.MANIFEST_PARSED, onManifest);
      }
    } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
      v.src = url;
      if (preserveTime) {
        const onMeta = () => seek(prevTime, wasPlaying);
        v.addEventListener('loadedmetadata', onMeta, { once: true });
      }
    } else {
      setError('error');
    }
  }, [destroyHls]);

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
        attachSource(p.stream_url, p.type, preserveTime);
      }

      if (p.expires_in && p.expires_in > 0) {
        if (refreshTimer.current) clearTimeout(refreshTimer.current);
        refreshTimer.current = setTimeout(() => loadPlayback(true), Math.max(30, p.expires_in - 60) * 1000);
      }
    } catch {
      setError('error');
    }
  }, [videoId, attachSource]);

  useEffect(() => {
    loadPlayback(false);
    return () => {
      if (refreshTimer.current) clearTimeout(refreshTimer.current);
      if (wmTimer.current) clearInterval(wmTimer.current);
      destroyHls();
    };
  }, [loadPlayback, destroyHls]);

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

      <div className="absolute top-2 end-2 px-2 py-0.5 rounded bg-black/50 text-[10px] text-white/70 pointer-events-none">
        محتوى محمي — يحظر التنزيل أو التصوير
      </div>
    </div>
  );
}
