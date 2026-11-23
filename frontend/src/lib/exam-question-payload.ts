import type { QuestionDraft } from '@/components/exam/question-builder';
import { gradingKeyToText, parseGradingKey } from '@/lib/interactive-question';

/**
 * The one place a teacher-facing question draft is turned into an API payload,
 * and a saved question back into a draft. Shared by the exam editor and the
 * standalone question bank so authoring a question (all ten types, including
 * the sandboxed HTML types) behaves identically wherever it happens — the only
 * difference is whether the new question is linked to an exam.
 */
export function draftFromQuestion(q: any, examId = ''): QuestionDraft {
  return {
    question_text: q.question_text || '',
    question_type: q.question_type || 'multiple_choice',
    points: String(q.points ?? '1'),
    exam_id: examId,
    section_id: q.section_id || '',
    options: (q.options && q.options.length ? q.options : [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }])
      .map((o: any) => ({ option_text: o.option_text || '', is_correct: !!o.is_correct })),
    image_url: q.metadata?.image_url || '',
    pairs: (q.metadata?.pairs || []).map((p: any) => ({ left: p.left || '', right: p.right || '' })),
    items: (q.metadata?.items || []).map((i: any) => (typeof i === 'string' ? i : i.text || '')),
    answers: (q.metadata?.answers || ['']).map((a: any) => String(a)),
    keywords: (q.metadata?.keywords || []).join(', '),
    skills: (q.metadata?.skills || []).join(', '),
    interactive_html: q.metadata?.interactive?.html || '',
    interactive_css: q.metadata?.interactive?.css || '',
    interactive_js: q.metadata?.interactive?.js || '',
    interactive_height: String(q.metadata?.interactive?.height ?? 360),
    interactive_auto_capture: q.metadata?.interactive?.auto_capture !== false,
    grading_mode: q.metadata?.grading?.mode === 'auto' ? 'auto' : 'manual',
    grading_rule: q.metadata?.grading?.rule || 'single_choice',
    grading_key: gradingKeyToText(q.metadata?.grading?.key),
    grading_tolerance: q.metadata?.grading?.tolerance != null ? String(q.metadata.grading.tolerance) : '',
  };
}

export type BankMeta = {
  course_id: string;
  difficulty: string;
  category: string;
  is_active: boolean;
  explanation: string;
};

export function bankMetaFromQuestion(q: any): BankMeta {
  return {
    course_id: q.course_id || '',
    difficulty: q.difficulty || 'medium',
    category: q.category || '',
    is_active: q.is_active !== false,
    explanation: q.explanation || '',
  };
}

/**
 * Build the request body for creating/updating a question from a draft.
 *
 * `courseId` scopes the question to a course (the bank allows course-less
 * questions too), and `examId` — when provided — attaches a brand-new question
 * to that exam in the same request.
 */
export function buildQuestionPayload(
  draft: QuestionDraft,
  opts: { courseId?: string | null; examId?: string | null; meta?: Partial<BankMeta> } = {},
): any {
  const meta: any = {};
  if (draft.image_url) meta.image_url = draft.image_url;

  const skills = draft.skills.split(',').map((s) => s.trim()).filter(Boolean);
  if (skills.length) meta.skills = skills;

  if (draft.question_type === 'drag_drop' || draft.question_type === 'matching') {
    meta.pairs = draft.pairs.filter((p) => p.left.trim() && p.right.trim());
  }
  if (draft.question_type === 'ordering') meta.items = draft.items.filter((i) => String(i).trim());
  if (draft.question_type === 'fill_blank') meta.answers = draft.answers.filter((a) => String(a).trim());
  if (draft.question_type === 'writing') meta.keywords = draft.keywords.split(',').map((k) => k.trim()).filter(Boolean);

  if (draft.question_type === 'interactive_html' || draft.question_type === 'custom_html') {
    meta.interactive = {
      html: draft.interactive_html,
      css: draft.interactive_css,
      js: draft.interactive_js,
      height: Number(draft.interactive_height) || 360,
      auto_capture: !!draft.interactive_auto_capture,
    };
    // The grading key is deliberately kept out of `interactive` so it can never
    // be serialized to a student.
    const grading: any = { mode: draft.grading_mode };
    if (draft.grading_mode === 'auto') {
      grading.rule = draft.grading_rule;
      grading.key = parseGradingKey(draft.grading_key);
      if (draft.grading_rule === 'numeric' && String(draft.grading_tolerance).trim() !== '') {
        grading.tolerance = Number(draft.grading_tolerance);
      }
    }
    meta.grading = grading;
  }

  const payload: any = {
    question_text: draft.question_text,
    question_type: draft.question_type,
    points: Number(draft.points) || 1,
    course_id: opts.courseId || null,
    section_id: draft.section_id || null,
    metadata: meta,
  };

  if (draft.question_type === 'multiple_choice') {
    payload.options = draft.options.filter((o) => o.option_text.trim());
  }
  if (draft.question_type === 'true_false') {
    payload.options = draft.options.length >= 2 ? draft.options : [{ option_text: 'True', is_correct: true }, { option_text: 'False', is_correct: false }];
  }

  if (opts.examId) payload.exam_id = opts.examId;

  if (opts.meta) {
    payload.difficulty = opts.meta.difficulty || 'medium';
    payload.category = opts.meta.category?.trim() ? opts.meta.category.trim() : null;
    payload.explanation = opts.meta.explanation?.trim() ? opts.meta.explanation.trim() : null;
    payload.is_active = opts.meta.is_active !== false;
  }

  return payload;
}
