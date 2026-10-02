import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'LMS for Independent Teachers and Tutors',
  description:
    'A learning management system designed for independent teachers and tutors: organize courses, track student progress, run quizzes and host live lessons — all under your own brand.',
  alternates: { canonical: '/lms-for-teachers' },
  openGraph: {
    title: 'LMS for Independent Teachers and Tutors',
    description: 'Courses, student progress, quizzes and live lessons in one branded learning-management system.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/lms-for-teachers'),
    locale: 'en_US',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'LMS for Independent Teachers', description: 'Run your own learning management system — no commissions, no middlemen.' },
};

const PILLARS: [string, string][] = [
  ['Course structure that makes sense', 'Organize lessons into sections, add video, files and quizzes, and control what students unlock and when.'],
  ['Student progress you can actually see', 'Enrollments, lesson completion, quiz scores and assignment results — one view per student, not scattered spreadsheets.'],
  ['Assessments that match how you teach', 'Quizzes with multiple question types and a question bank you can reuse across courses.'],
  ['Live lessons as part of the flow', 'Schedule a live class on Google Meet from the same system where the course lives, with attendance tracked.'],
  ['A campus your students recognize', 'Your logo, your colors, your domain. Students log into your academy, not a generic marketplace.'],
  ['Simple subscription pricing', 'Pick a monthly or yearly plan. No per-student fees that grow as you grow.'],
];

const FAQ: [string, string][] = [
  ['What is an LMS and why do I need one as a solo teacher?', 'An LMS (learning management system) is where your courses, students and assessments live together. It replaces the WhatsApp-plus-Drive-plus-spreadsheet setup most independent teachers suffer with.'],
  ['Is Tadreesly built for one teacher or whole schools?', 'It starts with you as an independent teacher or tutor, and scales as you grow. You control everything from one dashboard.'],
  ['Can I upload existing video lessons?', 'Yes. Upload recorded video per lesson, add files and structured content, and reuse them across courses.'],
  ['Do I have to pay per student?', 'No. You pay a flat monthly or yearly subscription for your plan. Add as many students as your plan allows without per-seat math.'],
  ['Can my students access it from their phones?', 'Yes — they open your platform in any browser. Plans with the student app give them a dedicated mobile app too.'],
];

export default function LmsForTeachersPage() {
  return (
    <main className="bg-white" dir="ltr">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'LMS for Teachers', href: '/lms-for-teachers' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            An LMS that finally fits an independent teacher
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            Stop juggling apps. Keep your courses, your students and your assessments in one
            learning management system — and present it under your own name.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              Try it free
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              Compare plans
            </a>
          </div>
        </header>

        <section aria-labelledby="problem-heading" className="mb-14">
          <h2 id="problem-heading" className="text-2xl font-bold text-gray-900">The LMS gap for independent teachers</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            School LMS platforms are built for institutions — IT teams, bulk imports, per-seat billing
            and complicated admin. Independent teachers need something different: set up a course in
            an evening, add a student in ten seconds, and know exactly who finished what. Tadreesly
            is that in-between system, made for you.
          </p>
        </section>

        <section aria-labelledby="pillars-heading" className="mb-14">
          <h2 id="pillars-heading" className="text-2xl font-bold text-gray-900">What your learning management system covers</h2>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {PILLARS.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="Set up your first course tonight"
          text="Create your account, build one section with a few lessons, and enroll a test student. You will see the whole system click into place."
          primaryLabel="Start free"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">Explore related guides</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Create and sell online courses</h3>
              <p className="text-gray-600 text-sm mt-1">Structure, price and publish recorded courses that sell on repeat.</p>
            </a>
            <a href="/online-teaching-platform" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Online teaching platform for teachers</h3>
              <p className="text-gray-600 text-sm mt-1">The full picture: courses, live classes, students and payments.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Live online classes</h3>
              <p className="text-gray-600 text-sm mt-1">Add live Google Meet lessons to your course flow.</p>
            </a>
            <a href="/teacher-website" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Teacher website platform</h3>
              <p className="text-gray-600 text-sm mt-1">Give your LMS a public face with your own branding.</p>
            </a>
          </div>
        </section>

        <Faq title="Frequently asked questions" items={FAQ} />

        <CtaSection
          title="Run your academy your way"
          text="Your courses, students and results belong to you. Manage them in an LMS designed for independent teachers."
          primaryLabel="Create your free account"
        />
      </div>
    </main>
  );
}