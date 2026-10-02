import { jsonLd, getSiteOrigin } from '@/lib/seo';

export type Crumb = { name: string; href: string };

export function Breadcrumbs({ items }: { items: Crumb[] }) {
  const origin = getSiteOrigin();
  const data = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((it, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: it.name,
      item: `${origin}${it.href}`,
    })),
  };
  return (
    <>
      <nav aria-label="Breadcrumb" className="text-sm text-gray-400 mb-6">
        <ol className="flex flex-wrap items-center gap-1.5">
          {items.map((it, i) => (
            <li key={it.href} className="flex items-center gap-1.5">
              {i > 0 && <span aria-hidden="true" className="text-gray-300">/</span>}
              {i < items.length - 1 ? (
                <a href={it.href} className="hover:text-teal-700 transition-colors">{it.name}</a>
              ) : (
                <span className="text-gray-500 font-medium">{it.name}</span>
              )}
            </li>
          ))}
        </ol>
      </nav>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(data) }} />
    </>
  );
}