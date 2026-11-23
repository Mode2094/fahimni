import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CourseCard, ActivationPanel, SectionHeading, StatStrip } from './storefront';
import { useLocale } from '@/store/locale';

const base = {
  primary: '#0d9488',
  accent: '#14b8a6',
  radius: '1rem',
  headingFont: "'Tajawal', sans-serif",
};

const COURSE = {
  id: 'c-1',
  title: 'أساسيات البرمجة',
  short_description: 'ابدأ من الصفر',
  description: 'وصف كامل',
  price: 0,
  currency: 'USD',
  duration_hours: 0,
  sections_count: 0,
  lessons_count: 0,
};

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
});

describe('CourseCard — real data only', () => {
  it('renders the real title and description', () => {
    render(<CourseCard course={COURSE} slug="trial-teacher" enrolled={false} {...base} />);
    expect(screen.getByText('أساسيات البرمجة')).toBeInTheDocument();
    expect(screen.getByText('ابدأ من الصفر')).toBeInTheDocument();
  });

  it('never invents a rating or student count', () => {
    const { container } = render(<CourseCard course={COURSE} slug="trial-teacher" enrolled={false} {...base} />);
    // No rating value, no star icon, no "students" label.
    expect(screen.queryByText('4.8')).toBeNull();
    expect(container.querySelector('.lucide-star')).toBeNull();
    expect(screen.queryByText(/طالب/)).toBeNull();
    expect(screen.queryByText(/تقييم/)).toBeNull();
  });

  it('shows a price badge only when the course actually has a price', () => {
    const { rerender } = render(<CourseCard course={COURSE} slug="s" enrolled={false} {...base} />);
    expect(screen.queryByText(/USD/)).toBeNull();

    rerender(<CourseCard course={{ ...COURSE, price: 120, currency: 'USD' }} slug="s" enrolled={false} {...base} />);
    expect(screen.getByText('120 USD')).toBeInTheDocument();
  });

  it('only renders meta chips backed by real counts', () => {
    const { rerender } = render(<CourseCard course={COURSE} slug="s" enrolled={false} {...base} />);
    expect(screen.queryByText(/وحدات/)).toBeNull();
    expect(screen.queryByText(/درس/)).toBeNull();

    rerender(<CourseCard course={{ ...COURSE, sections_count: 3, lessons_count: 12, duration_hours: 5 }} slug="s" enrolled={false} {...base} />);
    expect(screen.getByText(/3\s*وحدات/)).toBeInTheDocument();
    expect(screen.getByText(/12\s*درس/)).toBeInTheDocument();
    expect(screen.getByText(/5\s*س/)).toBeInTheDocument();
  });

  it('offers enrollment when not enrolled', () => {
    render(<CourseCard course={COURSE} slug="trial-teacher" enrolled={false} {...base} />);
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/t/trial-teacher/courses/c-1');
    expect(screen.getByText('عرض التفاصيل والتسجيل')).toBeInTheDocument();
  });

  it('offers "continue learning" based on real enrollment', () => {
    render(<CourseCard course={COURSE} slug="trial-teacher" enrolled {...base} />);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/course/c-1/learn');
    expect(screen.getByText('تابع التعلم')).toBeInTheDocument();
  });
});

describe('ActivationPanel — simple, mobile-first, clear errors', () => {
  const props = {
    slug: 'trial-teacher',
    primary: '#0d9488',
    accent: '#14b8a6',
    headingFont: "'Tajawal', sans-serif",
    radius: '1rem',
    value: '',
    onChange: () => {},
    submitting: false,
    successMessage: '',
    error: '',
    onSubmit: () => {},
  };

  it('asks a visitor to sign up first — no code input for guests', () => {
    render(<ActivationPanel loggedIn={false} {...props} />);
    expect(screen.getByRole('link', { name: 'إنشاء حساب' })).toBeInTheDocument();
    expect(screen.queryByLabelText('أدخل رمز البطاقة')).toBeNull();
  });

  it('shows a single code input for a signed-in student', () => {
    render(<ActivationPanel loggedIn {...props} />);
    expect(screen.getByLabelText('أدخل رمز البطاقة')).toBeInTheDocument();
    expect(screen.getAllByRole('textbox')).toHaveLength(1);
  });

  it('keeps the activate button disabled until a real code is typed', async () => {
    const onChange = vi.fn();
    const { rerender } = render(<ActivationPanel loggedIn {...props} value="" onChange={onChange} />);
    expect(screen.getByRole('button', { name: /تفعيل/ })).toBeDisabled();

    rerender(<ActivationPanel loggedIn {...props} value="ABCD-1234" onChange={onChange} />);
    expect(screen.getByRole('button', { name: /تفعيل/ })).toBeEnabled();
  });

  it('surfaces activation errors plainly', () => {
    render(<ActivationPanel loggedIn {...props} error="الرمز مستخدم بالفعل" />);
    expect(screen.getByRole('alert')).toHaveTextContent('الرمز مستخدم بالفعل');
  });

  it('links to the student courses after a successful activation', () => {
    render(<ActivationPanel loggedIn {...props} successMessage="تم التسجيل في الكورس" />);
    expect(screen.getByRole('status')).toHaveTextContent('تم التسجيل في الكورس');
    expect(screen.getByRole('link', { name: /كورساتي/ })).toHaveAttribute('href', '/my-learning');
  });
});

describe('StatStrip / SectionHeading', () => {
  it('renders nothing when there are no stats (never fake placeholders)', () => {
    const { container } = render(<StatStrip stats={[]} primary="#0d9488" headingFont="sans-serif" />);
    expect(container).toBeEmptyDOMElement();
  });

  it('renders exactly the teacher-entered figures', () => {
    render(
      <StatStrip
        stats={[{ value: '+120', label: 'طالب مسجّل' }, { value: '+8', label: 'كورس' }]}
        primary="#0d9488"
        headingFont="sans-serif"
      />,
    );
    expect(screen.getByText('طالب مسجّل')).toBeInTheDocument();
    expect(screen.getByText('كورس')).toBeInTheDocument();
    expect(screen.queryByText('تقييم')).toBeNull();
    expect(screen.queryByText('دعم')).toBeNull();
  });

  it('renders the section title', () => {
    render(<SectionHeading title="اختر كورسك" primary="#0d9488" headingFont="sans-serif" />);
    expect(screen.getByRole('heading', { name: 'اختر كورسك' })).toBeInTheDocument();
  });
});
