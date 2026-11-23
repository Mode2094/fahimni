import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import TeacherLanding from './landing';
import { useLocale } from '@/store/locale';

const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

const SITE = {
  id: 't1',
  slug: 'trial-teacher',
  name: 'أكاديمية التجربة',
  logo_url: null,
  primary_color: '#0d9488',
  secondary_color: '#0f172a',
  accent_color: '#14b8a6',
  site_config: {
    template: 'modern-dark',
    font: 'tajawal',
    sections: ['stats', 'about', 'courses', 'card', 'testimonials', 'faq', 'cta'],
    badge_text: 'أكاديمية تعليمية',
    hero_slides: [{ title: 'مرحباً بك في أكاديميتنا', subtitle: 'تعلّم بالخطوات' }],
    show_stats: false,
    stats: [],
    show_about: false,
    show_testimonials: false,
    show_faq: false,
  },
};

const COURSES = {
  courses: [
    {
      id: 'c-1',
      title: 'أساسيات البرمجة',
      short_description: 'ابدأ من الصفر',
      price: 0,
      currency: 'USD',
      duration_hours: 4,
      sections_count: 2,
      lessons_count: 9,
    },
  ],
};

/** Route the stubbed fetch: the more specific `/courses` path wins. */
function routeFetch(site: unknown = SITE) {
  return vi.fn((url: unknown) => {
    const u = String(url);
    if (u.includes('/public/trial-teacher/courses')) return ok(COURSES);
    if (u.includes('/public/trial-teacher')) return ok({ site });
    if (u.includes('/my-courses')) return ok({ enrollments: [] });
    return Promise.resolve({ ok: false, json: async () => ({}) } as any);
  });
}

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  vi.stubGlobal('fetch', routeFetch());
});

/** Next.js provides params as a promise; here we pass the resolved object. */
function renderLanding(site: unknown = SITE) {
  if (site !== SITE) vi.stubGlobal('fetch', routeFetch(site));
  return render(<TeacherLanding params={{ slug: 'trial-teacher' }} />);
}

describe('Teacher landing — student-facing storefront', () => {
  it('shows the teacher identity and the real catalog', async () => {
    renderLanding();

    expect((await screen.findAllByText('أكاديمية التجربة')).length).toBeGreaterThan(0);
    expect(screen.getByText('أساسيات البرمجة')).toBeInTheDocument();
    // Real meta only (sections/lessons from the API).
    expect(screen.getByText(/2\s*وحدات/)).toBeInTheDocument();
  });

  it('leads with the two approved hero actions', async () => {
    renderLanding();
    await screen.findAllByText('أكاديمية التجربة');

    const browse = screen.getByRole('link', { name: /استكشف الكورسات/ });
    expect(browse).toHaveAttribute('href', '#courses');
    const how = screen.getByRole('link', { name: /كيف تبدأ؟/ });
    expect(how).toHaveAttribute('href', '#card');
  });

  it('never shows invented ratings or marketing stats', async () => {
    const { container } = renderLanding();
    await screen.findAllByText('أكاديمية التجربة');

    expect(screen.queryByText('4.8')).toBeNull();
    expect(screen.queryByText('4.9')).toBeNull();
    expect(container.querySelector('.lucide-star')).toBeNull();
    // Stats bar is off by default → no "+500" / "طالب" anywhere.
    expect(screen.queryByText('+500')).toBeNull();
    expect(screen.queryByText('طالب')).toBeNull();
  });

  it('hides sections the teacher turned off, and always keeps courses + activation', async () => {
    renderLanding();
    await screen.findAllByText('أكاديمية التجربة');

    expect(screen.queryByRole('heading', { name: 'ماذا يقول طلابنا' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'الأسئلة الشائعة' })).toBeNull();
    expect(screen.getByText('عندك بطاقة تسجيل؟')).toBeInTheDocument();
  });

  it('shows the per-course enrollment path for a guest', async () => {
    renderLanding();
    await screen.findByText('أساسيات البرمجة');
    expect(screen.getByRole('link', { name: /التفاصيل والتسجيل/ })).toHaveAttribute(
      'href',
      '/t/trial-teacher/register?course=c-1',
    );
  });

  it('renders the server payload without a duplicate fetch or spinner flash', () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    render(
      <TeacherLanding
        params={{ slug: 'trial-teacher' }}
        initialSite={SITE}
        initialCourses={COURSES.courses}
      />,
    );

    // Content is painted on the first render…
    expect(screen.getAllByText('أكاديمية التجربة').length).toBeGreaterThan(0);
    expect(screen.getByText('أساسيات البرمجة')).toBeInTheDocument();
    // …and the client never repeats the requests the server already made.
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('shows a real not-found page (not an endless spinner) for an unknown platform', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((url: unknown) => (String(url).includes('/courses') ? ok({ courses: [] }) : ok({ site: null }))),
    );

    render(<TeacherLanding params={{ slug: 'ghost' }} />);

    expect(await screen.findByText('لم نعثر على هذه المنصة')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /استكشف الكورسات/ })).toBeNull();
  });
});

describe('Teacher landing — every template and custom identity', () => {
  const TEMPLATE_IDS = ['modern-dark', 'bright-modern', 'luxury', 'academic'] as const;

  for (const template of TEMPLATE_IDS) {
    it(`renders the "${template}" template with its hero + real catalog`, async () => {
      const site = {
        ...SITE,
        // A bespoke identity — never the platform defaults.
        primary_color: '#7c3aed',
        secondary_color: '#1e1b4b',
        accent_color: '#f59e0b',
        site_config: { ...SITE.site_config, template },
      };
      const { container } = renderLanding(site);

      expect((await screen.findAllByText('أكاديمية التجربة')).length).toBeGreaterThan(0);
      // Hero heading + the two approved actions survive every template.
      expect(screen.getByText('مرحباً بك في أكاديميتنا')).toBeInTheDocument();
      expect(screen.getByRole('link', { name: /استكشف الكورسات/ })).toBeInTheDocument();
      expect(screen.getByRole('link', { name: /كيف تبدأ؟/ })).toBeInTheDocument();

      // The teacher's own colors are applied (not a hard-coded palette).
      const styled = Array.from(container.querySelectorAll<HTMLElement>('[style]')).some(
        (el) => (el.getAttribute('style') || '').includes('124, 58, 237'),
      );
      expect(styled).toBe(true);
    });
  }

  it('keeps the catalog + activation reachable even when sections are reordered', async () => {
    const site = {
      ...SITE,
      site_config: {
        ...SITE.site_config,
        // Only courses + activation requested, custom order.
        sections: ['card', 'courses'],
        show_stats: false,
        show_about: false,
        show_testimonials: false,
        show_faq: false,
      },
    };
    renderLanding(site);

    await screen.findByText('أساسيات البرمجة');
    expect(screen.getByText('عندك بطاقة تسجيل؟')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /التفاصيل والتسجيل/ })).toBeInTheDocument();
  });
});
