import type { MetadataRoute } from 'next';
import { getSiteOrigin, absoluteUrl } from '@/lib/seo';

export const dynamic = 'force-dynamic';

const API_TARGET = process.env.NEXT_REWRITE_API_TARGET || 'http://localhost:8080/api/v1';

async function getPublicEntries(): Promise<{ teachers: any[]; posts: any[] }> {
  try {
    const res = await fetch(`${API_TARGET}/public/sitemap`, { cache: 'no-store' });
    if (!res.ok) return { teachers: [], posts: [] };
    const d = await res.json();
    return { teachers: d.teachers || [], posts: d.posts || [] };
  } catch {
    return { teachers: [], posts: [] };
  }
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const origin = getSiteOrigin();
  const { teachers, posts } = await getPublicEntries();

  const staticPages: Array<[string, string, number, 'daily' | 'weekly' | 'monthly' | 'yearly']> = [
    ['/', 'daily', 1, 'weekly'],
    ['/for-teachers', 'weekly', 0.8, 'monthly'],
    ['/online-teaching-platform', 'weekly', 0.8, 'monthly'],
    ['/lms-for-teachers', 'weekly', 0.8, 'monthly'],
    ['/create-online-courses', 'weekly', 0.8, 'monthly'],
    ['/live-online-classes', 'weekly', 0.8, 'monthly'],
    ['/teacher-website', 'weekly', 0.8, 'monthly'],
    ['/pricing', 'monthly', 0.8, 'monthly'],
    ['/blog', 'weekly', 0.7, 'weekly'],
    ['/contact', 'monthly', 0.5, 'monthly'],
    ['/support', 'monthly', 0.5, 'monthly'],
    ['/terms', 'yearly', 0.2, 'yearly'],
    ['/privacy', 'yearly', 0.2, 'yearly'],
    ['/refund-policy', 'yearly', 0.2, 'yearly'],
  ];

  const entries: MetadataRoute.Sitemap = staticPages.map(([path, lastMod, priority, freq]) => ({
    url: absoluteUrl(path),
    lastModified: lastMod === 'yearly' ? new Date('2026-01-01') : new Date(),
    changeFrequency: freq,
    priority,
  }));

  for (const t of teachers) {
    if (!t?.slug) continue;
    entries.push({
      url: `${origin}/t/${t.slug}`,
      lastModified: t.updated_at ? new Date(t.updated_at) : new Date(),
      changeFrequency: 'weekly',
      priority: 0.6,
    });
    for (const post of t.posts || []) {
      if (!post?.slug) continue;
      entries.push({
        url: `${origin}/t/${t.slug}/blog/${post.slug}`,
        lastModified: post.updated_at ? new Date(post.updated_at) : new Date(),
        changeFrequency: 'monthly',
        priority: 0.5,
      });
    }
  }

  return entries;
}