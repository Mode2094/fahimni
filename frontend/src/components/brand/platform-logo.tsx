import Image from 'next/image';

/**
 * الشعار الرسمي للمنصة — «منصة فهمني».
 *
 * يُستخدم كلما احتاجت واجهةٌ إلى شعار المنصة ولا يوجد شعار مخصص للمعلم
 * (هيدر/فوتر/صفحات الدخول والتسجيل/صفحات الخطأ). الملف مربّع 512×512 بأبعاد
 * صريحة، فلا يتمدّد أو يتشوّه.
 *
 * Uses `next/image` because the source is a fixed, same-origin asset
 * (`/logo.png`) — the browser gets an optimised, correctly sized response and
 * the intrinsic dimensions are known up front (no layout shift). Avatars and
 * teacher-uploaded logos stay plain `<img>`: their host is dynamic and would
 * require a wildcard `images.remotePatterns`, which would defeat the optimiser.
 */
export default function PlatformLogo({ className = '' }: { className?: string }) {
  return (
    <Image
      src="/logo.png"
      alt="منصة فهمني"
      width={512}
      height={512}
      priority
      className={className}
    />
  );
}
