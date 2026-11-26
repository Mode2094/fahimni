'use client';

import { useEffect, useRef, useState } from 'react';

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

/**
 * العلامة المائية المتحركة + حاجز خفيف ضد إزالتها من أدوات المطوّر.
 *
 * نصريحٌ لا ادّعاء: هذا لا يمنع تسجيل الشاشة ولا يضمن منع نسخ المحتوى. وظيفته
 * أنه يطبع اسم الطالب ووقت المشاهدة على الصورة نفسها، فيصبح أي تسجيل أو لقطة
 * شاشة قابلاً للتتبّع. كما يرفع كلفة إزالة العلامة عبر التلاعب بالواجهة
 * (إخفاء/حذف العنصر). تعمل فوق عناصر <video> و<iframe> على حد سواء.
 *
 * Shared verbatim by the protected player and the teacher-preview panel, so what
 * a teacher previews in settings is exactly what a student sees during playback.
 */
export function WatermarkOverlay({
  text, opacity, size, speed,
}: {
  text: string;
  opacity: number;
  size: string;
  speed: string;
}) {
  const [pos, setPos] = useState(0);
  const [stamp, setStamp] = useState(() => new Date());
  const ref = useRef<HTMLDivElement | null>(null);
  const lastRestore = useRef(0);

  useEffect(() => {
    const cfg = SPEED_CONFIG[speed] ?? SPEED_CONFIG.slow;
    const id = setInterval(() => setPos((p) => (p + 1) % WATERMARK_POSITIONS.length), cfg.interval);
    return () => clearInterval(id);
  }, [speed]);

  // طابع زمني يتحرك مع العلامة — يجعل كل تسجيل/لقطة قابلاً للتتبّع.
  useEffect(() => {
    const id = setInterval(() => setStamp(new Date()), 30_000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    const el = ref.current;
    const host = el?.parentElement;
    if (!el || !host) return;

    const enforce = () => {
      const now = Date.now();
      // Batch: never fight ourselves more than ~4×/s.
      if (now - lastRestore.current < 250) return;
      lastRestore.current = now;
      if (!host.contains(el)) host.appendChild(el);
      if (el.style.display === 'none') el.style.display = '';
      if (el.style.visibility === 'hidden') el.style.visibility = 'visible';
    };

    const observer = new MutationObserver(enforce);
    observer.observe(host, { childList: true, attributes: true, attributeFilter: ['style', 'class'] });
    const selfObserver = new MutationObserver(enforce);
    selfObserver.observe(el, { attributes: true, attributeFilter: ['style', 'class'] });

    return () => { observer.disconnect(); selfObserver.disconnect(); };
  }, []);

  const p = WATERMARK_POSITIONS[pos];
  const cfg = SPEED_CONFIG[speed] ?? SPEED_CONFIG.slow;
  const stampText = `${stamp.getFullYear()}-${String(stamp.getMonth() + 1).padStart(2, '0')}-${String(stamp.getDate()).padStart(2, '0')} ${String(stamp.getHours()).padStart(2, '0')}:${String(stamp.getMinutes()).padStart(2, '0')}`;

  return (
    <div
      ref={ref}
      aria-hidden
      data-watermark
      className="pointer-events-none absolute z-10 select-none whitespace-nowrap"
      style={{
        top: p.top,
        left: p.left,
        opacity,
        fontSize: size,
        color: '#fff',
        textShadow: '0 1px 3px rgba(0,0,0,0.5)',
        transition: `top ${cfg.transition}ms cubic-bezier(0.16,1,0.3,1), left ${cfg.transition}ms cubic-bezier(0.16,1,0.3,1), opacity 600ms ease`,
        fontWeight: 600,
        letterSpacing: '0.02em',
      }}
    >
      <span>{text}</span>
      <span className="ms-2 font-normal opacity-80" dir="ltr">{stampText}</span>
    </div>
  );
}
