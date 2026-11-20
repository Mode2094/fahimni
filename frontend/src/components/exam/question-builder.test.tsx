import { describe, it, expect } from 'vitest';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { QuestionBuilder, type QuestionDraft } from './question-builder';

/**
 * The interactive question and the HTML question are two INDEPENDENT
 * teacher-facing options that happen to share the same isolated sandbox and
 * server-side grader. These tests lock that split in so a future change cannot
 * quietly merge them back into one type (the exact regression we are fixing).
 */
const EMPTY: QuestionDraft = {
  question_text: '', question_type: 'multiple_choice', points: '1', exam_id: '', section_id: '',
  options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }],
  image_url: '', pairs: [], items: [], answers: [''], keywords: '',
  interactive_html: '', interactive_css: '', interactive_js: '', interactive_height: '360',
  interactive_auto_capture: true, grading_mode: 'manual', grading_rule: 'single_choice', grading_key: '', grading_tolerance: '',
};

/** Controlled host so we can observe exactly what the builder emits. */
function Harness() {
  const [value, setValue] = useState<QuestionDraft>(EMPTY);

  return (
    <QuestionBuilder
      value={value}
      onChange={setValue}
      sections={[{ id: 'sec-1', title: 'الوحدة الأولى' }]}
      exams={[{ id: 'exam-1', title: 'اختبار الوحدة' }]}
      lockExam
    />
  );
}

const TYPE_LABELS = [
  'اختيار متعدد', 'صح / خطأ', 'سحب وإفلات', 'مطابقة', 'ترتيب',
  'املأ الفراغ', 'إجابة كتابية', 'رفع ملف', 'سؤال تفاعلي', 'سؤال HTML',
];

describe('QuestionBuilder question types', () => {
  it('offers ten independent options, including «سؤال تفاعلي» and «سؤال HTML»', () => {
    render(<Harness />);

    for (const label of TYPE_LABELS) {
      expect(screen.getByText(label)).toBeTruthy();
    }
  });

  it('keeps «سؤال HTML» on its own id (custom_html) and opens the HTML/CSS/JS editor', () => {
    const { container } = render(<Harness />);

    fireEvent.click(screen.getByText('سؤال HTML'));

    // The selected type button is the HTML one, and it is NOT the interactive id.
    const htmlButton = screen.getByText('سؤال HTML').closest('button')!;
    expect(htmlButton.className).toContain('border-teal-500');
    expect(screen.getByText('سؤال تفاعلي').closest('button')!.className).not.toContain('border-teal-500');

    // The dedicated editor (with HTML/CSS/JS tabs) and the HTML-specific hint.
    expect(screen.getByText('كود السؤال')).toBeTruthy();
    expect(screen.getByText('HTML')).toBeTruthy();
    expect(screen.getByText('CSS')).toBeTruthy();
    expect(screen.getByText('JavaScript')).toBeTruthy();
    expect(container.textContent).toContain('سؤال HTML:');
  });

  it('keeps «سؤال تفاعلي» on its own id (interactive_html) with its original editor', () => {
    const { container } = render(<Harness />);

    fireEvent.click(screen.getByText('سؤال تفاعلي'));

    expect(screen.getByText('سؤال تفاعلي').closest('button')!.className).toContain('border-teal-500');
    expect(screen.getByText('سؤال HTML').closest('button')!.className).not.toContain('border-teal-500');

    expect(screen.getByText('كود السؤال')).toBeTruthy();
    expect(container.textContent).toContain('سؤال تفاعلي:');
  });
});
