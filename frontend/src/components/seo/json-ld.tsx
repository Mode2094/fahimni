import { jsonLd } from '@/lib/seo';

/**
 * Renders one or more JSON-LD blocks. Server component (zero client JS).
 * `<` is escaped by `jsonLd()` so the payload cannot break out of the script.
 */
export function JsonLd({ data }: { data: unknown | unknown[] }) {
  const blocks = (Array.isArray(data) ? data : [data]).filter(Boolean) as unknown[];
  return (
    <>
      {blocks.map((block, i) => (
        <script
          key={i}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: jsonLd(block) }}
        />
      ))}
    </>
  );
}
