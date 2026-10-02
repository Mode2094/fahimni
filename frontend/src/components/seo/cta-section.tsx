export function CtaSection({
  title,
  text,
  primaryLabel,
  primaryHref = '/register/teacher',
  secondaryLabel = 'شاهد الأسعار',
  secondaryHref = '/pricing',
}: {
  title: string;
  text: string;
  primaryLabel: string;
  primaryHref?: string;
  secondaryLabel?: string;
  secondaryHref?: string;
}) {
  return (
    <section className="rounded-3xl overflow-hidden my-16">
      <div className="relative px-6 sm:px-12 py-14 text-center text-white" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #0d9488 55%, #115e54 100%)' }}>
        <h2 className="text-2xl sm:text-3xl font-black leading-snug max-w-2xl mx-auto">{title}</h2>
        <p className="text-white/75 mt-3 max-w-xl mx-auto leading-relaxed">{text}</p>
        <div className="flex flex-wrap justify-center gap-3 mt-8">
          <a
            href={primaryHref}
            className="px-8 py-3.5 rounded-2xl font-bold text-teal-950 bg-white shadow-lg hover:scale-[1.02] transition-transform"
          >
            {primaryLabel}
          </a>
          <a
            href={secondaryHref}
            className="px-8 py-3.5 rounded-2xl font-bold text-white border-2 border-white/40 hover:bg-white/10 transition-colors"
          >
            {secondaryLabel}
          </a>
        </div>
      </div>
    </section>
  );
}