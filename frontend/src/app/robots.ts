import type { MetadataRoute } from 'next';
import { getSiteOrigin } from '@/lib/seo';

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: [
          '/admin/',
          '/teacher/',
          '/student/',
          '/checkout/',
          '/auth/',
          '/register/',
          '/forgot-password/',
          '/verify/',
          '/verify-email/',
          '/invite/',
          '/book/',
          '/card/',
          '/course/',
          '/courses/',
          '/exam/',
          '/exams/',
          '/live/',
          '/messages/',
          '/my-bookings/',
          '/my-learning/',
          '/notifications/',
          '/profile/',
          '/results/',
          '/students/',
          '/leaderboard/',
          '/analytics/',
          '/announcements/',
          '/community/',
          '/api/',
          '/_next/',
        ],
      },
    ],
    sitemap: `${getSiteOrigin()}/sitemap.xml`,
  };
}