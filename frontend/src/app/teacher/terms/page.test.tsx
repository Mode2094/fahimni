import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import TermsPage from './page';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

/** A resolved fetch-like response. */
const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

/** Route a stubbed global fetch by URL substring (most specific first). */
function routeFetch(routes: [string, () => Promise<any>][]) {
  return vi.fn((url: unknown) => {
    const u = String(url);
    const hit = routes.find(([k]) => u.includes(k));
    return hit ? hit[1]() : Promise.resolve({ ok: false, json: async () => ({}) } as any);
  });
}

const TERMS = {
  terms: [
    {
      id: 't1', name: 'الفصل الأول 2026', status: 'active', derived_status: 'active',
      starts_at: '2026-09-01T00:00:00Z', ends_at: '2026-12-31T00:00:00Z', enrollments_count: 12,
    },
    {
      id: 't2', name: 'فصل مؤرشف', status: 'archived', derived_status: 'archived',
      starts_at: null, ends_at: null, enrollments_count: 0,
    },
  ],
  seats: { used: 20, limit: 50, remaining: 30, unlimited: false },
};

const PREVIEW_WITH_STUDENTS = {
  preview: {
    students_count: 2,
    students: [{ user_id: 'u1', name: 'طالب أ', email: 'a@x.test', courses: [{ id: 'c1', title: 'كورس الرياضيات' }] }],
    seats: { used: 20, limit: 50, remaining: 30, unlimited: false },
    seats_before: 20, seats_after: 18, seats_freed: 2,
  },
  signature: 'sig-123',
};

const PREVIEW_EMPTY = {
  preview: { students_count: 0, students: [], seats: TERMS.seats, seats_before: 20, seats_after: 20, seats_freed: 0 },
  signature: 'sig-0',
};

beforeEach(() => {
  localStorage.clear();
  useAuth.setState({ user: { name: 'أ. سامي' } as any, check: () => true } as any);
  useLocale.setState({ locale: 'ar' });
});

describe('Teacher Terms page — guidance', () => {
  it('shows the concise intro with the suggested explanation', async () => {
    vi.stubGlobal('fetch', routeFetch([['/terms', () => ok(TERMS)]]));

    render(<TermsPage />);

    expect(await screen.findByText('ما فائدة الفصول الدراسية؟')).toBeInTheDocument();
    expect(
      screen.getByText(/الفصول الدراسية تساعدك على تنظيم طلابك حسب الفترة الدراسية/),
    ).toBeInTheDocument();
    // The detailed guide is closed by default so it never crowds the page.
    expect(screen.getByTestId('terms-guide-toggle')).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByTestId('terms-guide')).toBeNull();
  });

  it('opens a three-step guide with the practical example and the counts note', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch([['/terms', () => ok(TERMS)]]));

    render(<TermsPage />);
    await screen.findByText('ما فائدة الفصول الدراسية؟');

    await user.click(screen.getByTestId('terms-guide-toggle'));

    const guide = screen.getByTestId('terms-guide');
    expect(guide).toBeInTheDocument();
    expect(screen.getByText('الخطوة الأولى — إنشاء الفصل')).toBeInTheDocument();
    expect(screen.getByText('الخطوة الثانية — ربط الطلاب')).toBeInTheDocument();
    expect(screen.getByText('الخطوة الثالثة — إنهاء الفصل وتجديد المقاعد')).toBeInTheDocument();
    // The example is shown verbatim.
    expect(screen.getByTestId('terms-example')).toHaveTextContent(/واستمر 15/);
    expect(screen.getByTestId('terms-example')).toHaveTextContent(/50 طالبًا/);
    // Enrollments vs. seats distinction.
    expect(screen.getByTestId('terms-counts-note')).toHaveTextContent(/مقعد واحد/);
  });

  it('shows a collapsible FAQ whose answers stay hidden until opened', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch([['/terms', () => ok(TERMS)]]));

    render(<TermsPage />);
    await screen.findByText('ما فائدة الفصول الدراسية؟');

    expect(screen.getByText('أسئلة شائعة')).toBeInTheDocument();
    expect(screen.getByTestId('faq-toggle-0')).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByTestId('faq-answer-0')).toBeNull();

    await user.click(screen.getByTestId('faq-toggle-0'));
    expect(screen.getByTestId('faq-answer-0')).toHaveTextContent(/لا يمكن حذفه/);
  });

  it('keeps the real term data and the existing actions', async () => {
    vi.stubGlobal('fetch', routeFetch([['/terms', () => ok(TERMS)]]));

    render(<TermsPage />);

    expect(await screen.findByText('الفصل الأول 2026')).toBeInTheDocument();
    expect(screen.getByText('فصل مؤرشف')).toBeInTheDocument();
    // The plan seat line is unchanged (used / limit · remaining).
    expect(screen.getByText(/20 \/ 50 طالب · متاح 30/)).toBeInTheDocument();
    // The primary action is still there.
    expect(screen.getByRole('button', { name: /فصل جديد/ })).toBeInTheDocument();
  });

  it('explains continuing vs. not-marked and warns before ending a term', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch([
      ['/end-preview', () => ok(PREVIEW_WITH_STUDENTS)],
      ['/terms', () => ok(TERMS)],
    ]));

    render(<TermsPage />);
    await screen.findByText('الفصل الأول 2026');

    await user.click(screen.getByRole('button', { name: /إنهاء الفصل وتجديد المقاعد/ }));

    // The wizard opens with the real preview and the contextual help.
    expect(await screen.findByTestId('seats-summary')).toBeInTheDocument();
    expect(screen.getByTestId('continue-help')).toHaveTextContent(/طالب مستمر/);
    expect(screen.getByTestId('continue-help')).toHaveTextContent(/غير المحدد/);
    expect(screen.getByTestId('end-final-warning')).toBeInTheDocument();
    // No zero-enrollment note when the term has students.
    expect(screen.queryByTestId('end-zero-note')).toBeNull();
    // The end confirmation is reachable (preview loaded).
    expect(screen.getByRole('button', { name: /تأكيد الإنهاء/ })).toBeEnabled();
  });

  it('states that ending an empty term frees no seats', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch([
      ['/end-preview', () => ok(PREVIEW_EMPTY)],
      ['/terms', () => ok(TERMS)],
    ]));

    render(<TermsPage />);
    await screen.findByText('الفصل الأول 2026');

    await user.click(screen.getByRole('button', { name: /إنهاء الفصل وتجديد المقاعد/ }));

    await waitFor(() => expect(screen.getByTestId('end-zero-note')).toBeInTheDocument());
    expect(screen.getByTestId('end-zero-note')).toHaveTextContent(/لن يحرّر أي مقعد/);
    expect(screen.queryByTestId('end-final-warning')).toBeNull();
  });

  it('renders the English / LTR copy for both the intro and the FAQ', async () => {
    useLocale.setState({ locale: 'en' });
    vi.stubGlobal('fetch', routeFetch([['/terms', () => ok(TERMS)]]));

    render(<TermsPage />);

    expect(await screen.findByText('What are academic terms for?')).toBeInTheDocument();
    expect(screen.getByText('How do terms work?')).toBeInTheDocument();
    expect(screen.getByText('Frequently asked questions')).toBeInTheDocument();
  });
});
