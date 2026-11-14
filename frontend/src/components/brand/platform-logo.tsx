/**
 * الشعار الرسمي للمنصة — «منصة فهمني».
 *
 * يُستخدم كلما احتاجت واجهةٌ إلى شعار المنصة ولا يوجد شعار مخصص للمعلم
 * (هيدر/فوتر/صفحات الدخول والتسجيل/صفحات الخطأ). الملف مربّع 512×512 بأبعاد
 * صريحة، فلا يتمدّد أو يتشوّه، ويُحمَّل بشكل غير حاجب (async) حتى لا يؤثر على
 * سرعة ظهور الصفحة.
 */
export default function PlatformLogo({ className = '' }: { className?: string }) {
  return (
    <img
      src="/logo.png"
      alt="منصة فهمني"
      width={512}
      height={512}
      decoding="async"
      loading="eager"
      className={className}
    />
  );
}
