import { jsonLd } from '@/lib/seo';

/**
 * Server-rendered FAQ. Uses <details>/<summary> so it works with zero JS.
 * Emits real FAQPage structured data for the questions actually answered on
 * this page (never generic filler).
 */
export function Faq({ title, items, hint }: { title: string; hint?: string; items: [string, string][] }) {
  const data = {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map(([q, a]) => ({
      '@type': 'Question',
      name: q,
      acceptedAnswer: { '@type': 'Answer', text: a },
    })),
  };
  return (
    <section aria-labelledby="faq-heading" className="max-w-3xl mx-auto">
      <h2 id="faq-heading" className="text-2xl sm:text-3xl font-bold text-gray-900 text-center">{title}</h2>
      {hint && <p className="text-gray-500 text-center mt-2 mb-8">{hint}</p>}
      <div className="space-y-3 mt-8">
        {items.map(([q, a]) => (
          <details key={q} className="group rounded-2xl border border-gray-200 bg-white p-5 open:border-teal-300 open:bg-teal-50/40">
            <summary className="flex cursor-pointer items-center justify-between gap-3 font-semibold text-gray-800 list-none [&::-webkit-details-marker]:hidden">
              {q}
              <span aria-hidden="true" className="text-teal-600 transition-transform group-open:rotate-45">＋</span>
            </summary>
            <p className="mt-3 text-gray-600 leading-relaxed">{a}</p>
          </details>
        ))}
      </div>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(data) }} />
    </section>
  );
}