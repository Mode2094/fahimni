import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CourseDetail from './course-detail';
import { useLocale } from '@/store/locale';

const SITE = {
  name: 'أكاديمية النور',
  slug: 'noor',
  primary_color: '#0d9488',
  accent_color: '#14b8a6',
  site_config: { contact_whatsapp: '962790000000', contact_phone: '0790000000' },
};

const COURSE = {
  id: 'c1',
  title: 'أساسيات الفيزياء',
  short_description: 'من الصفر للاحتراف',
  description: 'وصف كامل',
  price: 49,
  currency: 'USD',
  duration_hours: 5,
  sections_count: 1,
  lessons_count: 2,
};

const SECTIONS = [
  {
    id: 's1',
    title: 'الوحدة الأولى',
    lessons: [
      { id: 'l1', title: 'درس مجاني', is_free_preview: true, duration_minutes: 10 },
      { id: 'l2', title: 'درس مدفوع', is_free_preview: false, duration_minutes: 20 },
    ],
  },
];

function json(body: unknown, ok = true) {
  return Promise.resolve({ ok, status: ok ? 200 : 404, json: () => Promise.resolve(body) } as any);
}

function mockFetch(map: Record<string, () => Promise<any>>) {
  return vi.fn((input: any) => {
    const url = String(input);
    for (const key of Object.keys(map)) {
      if (url.includes(key)) return map[key]();
    }
    return json({});
  });
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem('lms_authed', '1');
  useLocale.setState({ locale: 'ar' });
});

describe('Public course detail page', () => {
  it('renders the real course, price, curriculum and a WhatsApp request button', async () => {
    global.fetch = mockFetch({
      '/my-courses': () => json({ enrollments: [] }),
      '/courses/c1': () => json({ course: COURSE, sections: SECTIONS }),
    }) as any;

    render(<CourseDetail slug="noor" courseId="c1" initialSite={SITE} />);

    expect(await screen.findByText('أساسيات الفيزياء')).toBeInTheDocument();
    expect(screen.getByText('من الصفر للاحتراف')).toBeInTheDocument();
    expect(screen.getByText('49 USD')).toBeInTheDocument();
    expect(screen.getByText('الوحدة الأولى')).toBeInTheDocument();

    const wa = screen.getByRole('link', { name: /اطلب بطاقة عبر واتساب/ });
    expect(wa).toHaveAttribute('href', expect.stringContaining('https://wa.me/962790000000'));
  });

  it('plays a free preview when the backend returns a public YouTube id', async () => {
    global.fetch = mockFetch({
      '/my-courses': () => json({ enrollments: [] }),
      '/lessons/l1': () => json({
        contents: [{ id: 'ct1', content_type: 'video', video: { id: 'v1', type: 'external', provider: 'youtube', provider_video_id: 'dQw4w9WgXcQ', requires_login: false } }],
      }),
      '/courses/c1': () => json({ course: COURSE, sections: SECTIONS }),
    }) as any;

    render(<CourseDetail slug="noor" courseId="c1" initialSite={SITE} />);
    await screen.findByText('أساسيات الفيزياء');

    await userEvent.click(screen.getByRole('button', { name: 'معاينة مجانية' }));

    await waitFor(() => {
      const iframe = document.querySelector('iframe');
      expect(iframe?.getAttribute('src')).toContain('youtube.com/embed/dQw4w9WgXcQ');
    });
  });

  it('asks a guest to sign up when a hosted preview needs a signed URL', async () => {
    localStorage.removeItem('lms_authed');
    global.fetch = mockFetch({
      '/my-courses': () => json({ enrollments: [] }),
      '/lessons/l1': () => json({
        contents: [{ id: 'ct1', content_type: 'video', video: { id: 'v1', type: 'provider', provider: 'bunny', provider_video_id: null, requires_login: true } }],
      }),
      '/courses/c1': () => json({ course: COURSE, sections: SECTIONS }),
    }) as any;

    render(<CourseDetail slug="noor" courseId="c1" initialSite={SITE} />);
    await screen.findByText('أساسيات الفيزياء');

    await userEvent.click(screen.getByRole('button', { name: 'معاينة مجانية' }));

    expect(await screen.findByText(/أنشئ حساباً مجانياً/)).toBeInTheDocument();
  });

  it('shows a clear empty state when the course is not available', async () => {
    global.fetch = mockFetch({
      '/my-courses': () => json({ enrollments: [] }),
      '/courses/c1': () => json({ message: 'Course not found.' }, false),
    }) as any;

    render(<CourseDetail slug="noor" courseId="c1" initialSite={SITE} />);

    expect(await screen.findByText('هذه الدورة غير متوفرة')).toBeInTheDocument();
  });
});
