'use client';

import { useState, type ComponentType, type ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
  ChevronDown, CalendarDays, Link2, Archive, Power, Plus, Users, GraduationCap, Lightbulb, Info,
} from 'lucide-react';

export type TermsT = (ar: string, en: string) => string;

/** A single inline disclosure: no dialog stacking, works on phone and desktop. */
function Collapsible({
  title, icon: Icon, children, defaultOpen = false, testId, contentTestId,
}: {
  title: string;
  icon?: ComponentType<{ className?: string }>;
  children: ReactNode;
  defaultOpen?: boolean;
  testId?: string;
  contentTestId?: string;
}) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div className="rounded-2xl border border-slate-100 bg-white">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        data-testid={testId}
        className="flex w-full items-center gap-3 p-4 text-start transition-colors hover:bg-muted/40"
      >
        {Icon && (
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-500/10">
            <Icon className="h-[18px] w-[18px] text-teal-600" />
          </span>
        )}
        <span className="min-w-0 flex-1 text-sm font-semibold">{title}</span>
        <ChevronDown className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200', open && 'rotate-180')} />
      </button>
      {open && (
        <div data-testid={contentTestId} className="px-4 pb-4 text-sm text-muted-foreground">
          {children}
        </div>
      )}
    </div>
  );
}

type Bi = { ar: string; en: string };

const STEPS: { icon: ComponentType<{ className?: string }>; title: Bi; body: Bi }[] = [
  {
    icon: CalendarDays,
    title: { ar: 'الخطوة الأولى — إنشاء الفصل', en: 'Step 1 — Create the term' },
    body: {
      ar: 'اختر اسم الفصل وتواريخ البداية والنهاية.',
      en: 'Choose the term name and its start and end dates.',
    },
  },
  {
    icon: Link2,
    title: { ar: 'الخطوة الثانية — ربط الطلاب', en: 'Step 2 — Link students' },
    body: {
      ar: 'اربط تسجيلات الطلاب الحاليين بالفصل. الربط لا يحذف أي بيانات ولا يعيد التسجيل من الصفر.',
      en: 'Link existing student enrollments to the term. Linking deletes nothing and never re-enrolls students from scratch.',
    },
  },
  {
    icon: Archive,
    title: { ar: 'الخطوة الثالثة — إنهاء الفصل وتجديد المقاعد', en: 'Step 3 — End the term & renew seats' },
    body: {
      ar: 'عندما تنتهي الدراسة، حدّد الطلاب المستمرين، واختر الفصل القادم، ثم راجع المقاعد التي ستتحرر قبل تأكيد الإنهاء.',
      en: 'When study ends, mark the continuing students, choose the next term, then review the seats that will be freed before you confirm.',
    },
  },
];

const BUTTONS: { icon: ComponentType<{ className?: string }>; label: Bi; body: Bi }[] = [
  {
    icon: Plus,
    label: { ar: 'فصل جديد', en: 'New Term' },
    body: { ar: 'لإنشاء فترة دراسية جديدة.', en: 'Creates a new academic period.' },
  },
  {
    icon: Power,
    label: { ar: 'تفعيل', en: 'Activate' },
    body: { ar: 'لبدء استخدام الفصل.', en: 'Starts using the term.' },
  },
  {
    icon: Link2,
    label: { ar: 'ربط طلاب حاليين', en: 'Link existing students' },
    body: {
      ar: 'لإضافة تسجيلاتهم الحالية إلى الفصل.',
      en: 'Adds their existing enrollments to the term.',
    },
  },
  {
    icon: Archive,
    label: { ar: 'إنهاء الفصل وتجديد المقاعد', en: 'End & renew seats' },
    body: {
      ar: 'لإنهاء التسجيلات المرتبطة بالفصل واختيار المستمرين وتحرير المقاعد المستحقة.',
      en: 'Ends the term’s enrollments, lets you pick who continues, and frees the seats due to be released.',
    },
  },
];

/**
 * Concise «what are terms for?» intro with an optional, closable detailed guide.
 * Kept short by default so an experienced teacher is never slowed down.
 */
export function TermsIntro({ t }: { t: TermsT }) {
  const [open, setOpen] = useState(false);

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-4 sm:p-5">
        <div className="flex items-start gap-3">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-teal-500/10">
            <GraduationCap className="h-5 w-5 text-teal-600" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="font-bold">{t('ما فائدة الفصول الدراسية؟', 'What are academic terms for?')}</h2>
            <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
              {t(
                'الفصول الدراسية تساعدك على تنظيم طلابك حسب الفترة الدراسية. عند انتهاء الفصل، يمكنك تحديد الطلاب المستمرين ونقل تسجيلاتهم إلى الفصل القادم، وتحرير مقاعد الطلاب الذين أنهوا دراستهم، مع الاحتفاظ بدرجاتهم ونتائجهم السابقة.',
                'Academic terms help you organise students by study period. When a term ends you can mark who continues, carry their enrollments into the next term, and free the seats of students who finished — while keeping their grades and past results.',
              )}
            </p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3 gap-1.5 rounded-full"
              onClick={() => setOpen((o) => !o)}
              aria-expanded={open}
              aria-controls="terms-guide"
              data-testid="terms-guide-toggle"
            >
              <ChevronDown className={cn('h-3.5 w-3.5 transition-transform duration-200', open && 'rotate-180')} />
              {t('كيف تعمل الفصول؟', 'How do terms work?')}
            </Button>
          </div>
        </div>

        {open && (
          <div id="terms-guide" data-testid="terms-guide" className="mt-4 space-y-5 border-t border-slate-100 pt-4">
            {/* Three steps */}
            <div className="grid gap-3 md:grid-cols-3">
              {STEPS.map((s, i) => {
                const Icon = s.icon;
                return (
                  <div key={i} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                    <div className="mb-2 flex items-center gap-2">
                      <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-teal-500/10">
                        <Icon className="h-4 w-4 text-teal-600" />
                      </span>
                      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-xs font-bold text-white">{i + 1}</span>
                    </div>
                    <p className="text-sm font-bold text-foreground">{t(s.title.ar, s.title.en)}</p>
                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{t(s.body.ar, s.body.en)}</p>
                  </div>
                );
              })}
            </div>

            {/* Practical example */}
            <div className="rounded-2xl border border-teal-100 bg-teal-50/70 p-4" data-testid="terms-example">
              <p className="flex items-center gap-1.5 text-sm font-bold text-teal-800">
                <Lightbulb className="h-4 w-4" />
                {t('مثال عملي', 'A practical example')}
              </p>
              <p className="mt-1.5 text-sm leading-relaxed text-teal-900/90">
                {t(
                  'إذا كانت خطتك تسمح بـ50 طالبًا، وكان لديك 50 طالبًا، وانتهى 35 منهم واستمر 15، يمكنك تجديد تسجيلات الـ15 في الفصل القادم، وتحرير حتى 35 مقعدًا.',
                  'If your plan allows 50 students, you have 50, 35 finished and 15 continue, you can renew the 15 in the next term and free up to 35 seats.',
                )}
              </p>
              <p className="mt-2 text-xs leading-relaxed text-teal-900/70">
                {t(
                  'العدد الفعلي يعتمد على وجود تسجيلات سارية أخرى للطلاب. والطالب يشغل مقعدًا واحدًا لديك حتى لو كان مسجّلًا في أكثر من كورس.',
                  'The real number depends on whether a student has other active enrollments. A student occupies one seat with you even if enrolled in more than one course.',
                )}
              </p>
            </div>

            {/* What each button does */}
            <div>
              <p className="mb-2 text-sm font-bold text-foreground">{t('ماذا يفعل كل زر؟', 'What each button does')}</p>
              <div className="grid gap-2 sm:grid-cols-2">
                {BUTTONS.map((b, i) => {
                  const Icon = b.icon;
                  return (
                    <div key={i} className="flex items-start gap-2.5 rounded-xl border border-slate-100 p-3">
                      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                        <Icon className="h-4 w-4 text-slate-600" />
                      </span>
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-foreground">{t(b.label.ar, b.label.en)}</p>
                        <p className="text-xs leading-relaxed text-muted-foreground">{t(b.body.ar, b.body.en)}</p>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Enrollments vs. seats */}
            <div className="flex items-start gap-2 rounded-xl bg-sky-50 p-3 text-xs leading-relaxed text-sky-800" data-testid="terms-counts-note">
              <Info className="mt-0.5 h-4 w-4 shrink-0" />
              <span>
                {t(
                  '«عدد التسجيلات» في الفصل يحصي كل تسجيل (طالب في 3 كورسات = 3 تسجيلات)، أما «المقاعد المستخدمة» في خطتك فتحصي الطلاب المميّزين (الطالب نفسه = مقعد واحد). وإذا لم يكن في الفصل أي تسجيل، فإن إنهاءه لن يحرّر أي مقعد.',
                  'A term’s “enrollment count” counts every enrollment (a student in 3 courses = 3), while the “seats used” in your plan counts distinct students (the same student = one seat). If a term has no enrollments, ending it frees no seats.',
                )}
              </span>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

const FAQ: { q: Bi; a: Bi }[] = [
  {
    q: { ar: 'هل حذف الفصل يحذف نتائج الطلاب؟', en: 'Does deleting a term delete student results?' },
    a: {
      ar: 'لا. الفصل المرتبط بتسجيلات لا يمكن حذفه — يجب إنهاؤه بدل ذلك. والحذف متاح فقط للفصول الفارغة بلا أي تسجيلات، لذلك لا يمكن أن يمس نتائج أي طالب.',
      en: 'No. A term that has enrollments cannot be deleted — it must be ended instead. Deletion is only allowed for empty terms, so it can never touch a student’s results.',
    },
  },
  {
    q: { ar: 'ماذا يحدث عندما أنهي الفصل؟', en: 'What happens when I end a term?' },
    a: {
      ar: 'تُؤرشف كل التسجيلات السارية في الفصل (تُحفظ صفوفها كما هي وتُوسم كمؤرشفة)، فيتحرر مقعد كل طالب لم تحدده للاستمرار وليس لديه تسجيل ساري آخر. ومن حدّدته مستمرًا مع اختيار فصل قادم تُنشأ له تسجيلات سارية جديدة في الفصل القادم لنفس الكورسات. درجاتهم ومحاولاتهم تبقى محفوظة، وتُصبح حالة الفصل «مؤرشف».',
      en: 'Every active enrollment in the term is archived (its row is kept and marked as archived), freeing the seat of each student you did not mark as continuing and who has no other active enrollment. Students you marked as continuing get new active enrollments in the next term for the same courses. Their grades and attempts are preserved, and the term becomes “Archived”.',
    },
  },
  {
    q: { ar: 'هل أستطيع تجديد طالب في الفصل القادم؟', en: 'Can I renew a student into the next term?' },
    a: {
      ar: 'نعم. أثناء «إنهاء الفصل» حدّد الطالب كمستمر واختر فصلًا قادمًا (موجودًا أو جديدًا)، فتُنشأ له تسجيلات سارية في الفصل القادم لنفس كورساته. يشترط أن يظل الطالب نشطًا لديك، وأن تسمح خطتك بالمقاعد وسعة الكورس.',
      en: 'Yes. While ending the term, mark the student as continuing and choose a next term (existing or new); new active enrollments are created in that term for their same courses. The student must still be active with you, and your plan’s seat and course capacity must allow it.',
    },
  },
  {
    q: { ar: 'هل الطالب المسجّل في ثلاثة كورسات يحجز ثلاثة مقاعد؟', en: 'Does a student in three courses hold three seats?' },
    a: {
      ar: 'لا. كل طالب يحجز مقعدًا واحدًا لديك مهما كان عدد كورساته. المقاعد تُحسب للطلاب المميّزين، لا لعدد التسجيلات.',
      en: 'No. Each student holds exactly one seat with you regardless of how many courses they take. Seats are counted per distinct student, not per enrollment.',
    },
  },
  {
    q: { ar: 'لماذا لم يتحرر مقعد طالب بعد الإنهاء؟', en: 'Why was a student’s seat not freed after ending?' },
    a: {
      ar: 'لأن الطالب ما زال لديه تسجيل ساري آخر خارج هذا الفصل: في كورس آخر غير مرتبط بالفصل، أو في فصل آخر، أو لأنك حددته مستمرًا فشغل تسجيله الجديد مقعده. المقعد لا يتحرر إلا إذا لم يبقَ للطالب أي تسجيل ساري.',
      en: 'Because the student still has another active enrollment outside this term: in another course not linked to the term, in another term, or because you marked them as continuing, so their new enrollment keeps the seat. A seat is only freed when the student has no active enrollment left.',
    },
  },
  {
    q: { ar: 'ماذا لو كان لدي طلاب قبل إنشاء أول فصل؟', en: 'What about students I had before the first term?' },
    a: {
      ar: 'تسجيلاتهم تسبق الفصول، لذا لا تنتمي لأي فصل. استخدم زر «ربط طلاب حاليين» داخل الفصل لإضافتهم إليه. الربط يضيف وسم الفصل على تسجيلاتهم الحالية فقط — بلا حذف ولا إعادة تسجيل ولا فقدان لأي تقدّم.',
      en: 'Their enrollments predate terms, so they belong to none. Use “Link existing students” inside a term to add them. Linking only tags their existing enrollments with the term — no deletion, no re-enrollment, no lost progress.',
    },
  },
  {
    q: { ar: 'هل ينتهي الفصل تلقائيًا في التاريخ المحدد؟', en: 'Does a term end automatically on its end date?' },
    a: {
      ar: 'لا. تاريخ النهاية يؤثر على عرض الحالة فقط (يظهر الفصل «منتهي» بعد مرور التاريخ)، لكنه لا يؤرشف التسجيلات ولا يحرّر المقاعد. الإنهاء يتم يدويًا عند ضغطك «إنهاء الفصل وتجديد المقاعد».',
      en: 'No. The end date only affects the displayed status (the term shows as “Completed” after that date); it never archives enrollments or frees seats. Ending happens only when you press “End & renew seats”.',
    },
  },
];

/** Collapsible FAQ. Answers describe the system’s real behaviour, not promises. */
export function TermsFaq({ t }: { t: TermsT }) {
  return (
    <Card className="border-0 shadow-sm" data-testid="terms-faq">
      <CardContent className="p-4 sm:p-5">
        <h2 className="mb-3 flex items-center gap-2 font-bold">
          <Users className="h-4 w-4 text-teal-600" />
          {t('أسئلة شائعة', 'Frequently asked questions')}
        </h2>
        <div className="space-y-2">
          {FAQ.map((item, i) => (
            <Collapsible
              key={i}
              title={t(item.q.ar, item.q.en)}
              testId={`faq-toggle-${i}`}
              contentTestId={`faq-answer-${i}`}
            >
              {t(item.a.ar, item.a.en)}
            </Collapsible>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
