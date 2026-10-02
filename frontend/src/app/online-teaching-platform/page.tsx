import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'Online Teaching Platform for Independent Teachers',
  description:
    'An online teaching platform built for independent teachers: recorded courses, live lessons, student management, quizzes and your own branded website — no marketplace commissions.',
  alternates: { canonical: '/online-teaching-platform' },
  openGraph: {
    title: 'Online Teaching Platform for Independent Teachers',
    description: 'Recorded courses, live lessons, student management and your own branded site — one platform, no middlemen.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/online-teaching-platform'),
    locale: 'en_US',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'Online Teaching Platform for Teachers', description: 'Your own branded teaching platform — no marketplace commissions.' },
};

const CAPABILITIES: [string, string][] = [
  ['Recorded video courses', 'A real course builder: sections, lessons, video and files. Publish once, sell repeatedly while you sleep.'],
  ['Live online classes', 'Run live lessons on Google Meet from inside your platform, with booking, attendance and automatic reminders.'],
  ['Student management', 'Enrollments, progress tracking, exam results and student profiles — every learner in one place.'],
  ['Quizzes and registration cards', 'Build quizzes with multiple question types, and use QR registration cards that enroll a student in one scan.'],
  ['Your own branding', 'A public site with your logo, colors and domain. You build your own name — not a marketplace’s.'],
  ['Direct payments', 'PayTabs, PayPal and more, with automatic invoices. Every payment lands in your account.'],
];

const FAQ: [string, string][] = [
  ['Do I need any technical skills to start?', 'No. Sign up, get your site instantly, add your first course and set your price. Everything is managed from a simple dashboard.'],
  ['Is this the same as selling on a marketplace?', 'No. A marketplace rents you a storefront inside someone else’s brand. Tadreesly gives you a platform under your own name, domain and control.'],
  ['Can I price my courses however I want?', 'Yes. You set every price, and payments go to you directly through your connected payment gateways.'],
  ['Do my students need to install anything?', 'No. They open your platform link in any browser. An optional student app is available on plans that include it.'],
  ['What does “independent teachers” mean here?', 'Teachers, tutors and academies that run their own classes and want to keep ownership of their content, students and revenue.'],
];

export default function OnlineTeachingPlatformPage() {
  return (
    <main className="bg-white" dir="ltr">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'Online Teaching Platform', href: '/online-teaching-platform' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            An online teaching platform that works for independent teachers
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            Teach recorded courses, run live classes, manage every student and sell from your own
            branded platform — instead of renting a storefront and paying a cut to someone else.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              Create your platform free
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              See pricing
            </a>
          </div>
        </header>

        <section aria-labelledby="problem-heading" className="mb-14">
          <h2 id="problem-heading" className="text-2xl font-bold text-gray-900">The problem with teaching online today</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            Independent teachers face a bad trade-off: sell on big marketplaces and hand over a
            commission on every student, or stitch together WhatsApp, Drive, Zoom and spreadsheets
            into a mess your students get lost in. Either way, you do the teaching — and someone
            else owns the students and the revenue stream.
          </p>
        </section>

        <section aria-labelledby="solution-heading" className="mb-14">
          <h2 id="solution-heading" className="text-2xl font-bold text-gray-900">The solution: your own platform, your own brand</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            Tadreesly moves you from tenant to owner. Your site, your content, your students and your
            revenue all stay yours. Everything below is already part of the platform:
          </p>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {CAPABILITIES.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="See how your own platform changes things"
          text="Sign up free, publish one course, share your link with one student. Then decide if you ever want to pay commissions again."
          primaryLabel="Start for free"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">Go deeper</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Create and sell online courses</h3>
              <p className="text-gray-600 text-sm mt-1">Turn your expertise into recorded courses that sell on repeat.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Live online classes</h3>
              <p className="text-gray-600 text-sm mt-1">Run live lessons on Google Meet with booking and attendance built in.</p>
            </a>
            <a href="/lms-for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">LMS for independent teachers</h3>
              <p className="text-gray-600 text-sm mt-1">Organize courses, students and quizzes in one learning-management system.</p>
            </a>
            <a href="/teacher-website" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Your own teacher website</h3>
              <p className="text-gray-600 text-sm mt-1">A branded public site that builds your name, not a marketplace’s.</p>
            </a>
          </div>
        </section>

        <Faq title="Questions teachers ask" items={FAQ} />

        <CtaSection
          title="Own your teaching, not just your lessons"
          text="Stop renting a storefront. Create your platform, keep your students and collect every payment yourself."
          primaryLabel="Create your free account"
        />
      </div>
    </main>
  );
}