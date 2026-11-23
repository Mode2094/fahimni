import { describe, it, expect } from 'vitest';
import { defaultDuplicateTitle, summarizeDuplicateReport, type DuplicateReport } from './duplicate';

const baseReport = (over: Partial<DuplicateReport> = {}): DuplicateReport => ({
  sections: 0,
  lessons: 0,
  contents: 0,
  videos_reused: 0,
  files_reused: 0,
  exams: 0,
  questions_reused: 0,
  assignments: 0,
  skipped: [],
  ...over,
});

describe('defaultDuplicateTitle', () => {
  it('appends an Arabic «نسخة» suffix and never leaves the name empty', () => {
    expect(defaultDuplicateTitle('رياضيات', 'ar')).toBe('رياضيات — نسخة');
    expect(defaultDuplicateTitle('   ', 'ar')).toBe('بدون عنوان — نسخة');
  });

  it('uses an English suffix for the English locale', () => {
    expect(defaultDuplicateTitle('Math', 'en')).toBe('Math — copy');
  });
});

describe('summarizeDuplicateReport', () => {
  it('reports copied structure, reused media and relinked bank questions', () => {
    const s = summarizeDuplicateReport(baseReport({
      sections: 2, lessons: 5, exams: 1, assignments: 1,
      videos_reused: 3, files_reused: 2, questions_reused: 8,
    }), 'ar');

    expect(s.hasWarnings).toBe(false);
    expect(s.title).toBe('تم إنشاء النسخة (مسودة)');
    expect(s.detail).toContain('2 وحدة');
    expect(s.detail).toContain('5 درس');
    expect(s.detail).toContain('أُعيد استخدام: 3 فيديو، 2 ملف');
    expect(s.detail).toContain('بلا نسخ تخزين إضافي');
    expect(s.detail).toContain('8 سؤال من بنك الأسئلة');
  });

  it('surfaces what was NOT copied (e.g. a live session) as a warning', () => {
    const s = summarizeDuplicateReport(baseReport({
      lessons: 1,
      skipped: [{ type: 'live', title: 'حصة مباشرة' }],
    }), 'ar');

    expect(s.hasWarnings).toBe(true);
    expect(s.detail).toContain('لم يُنسخ');
    expect(s.detail).toContain('البث المباشر: حصة مباشرة');
  });

  it('never claims a copy is empty when the source has nothing copyable', () => {
    const s = summarizeDuplicateReport(baseReport(), 'en');
    expect(s.detail).toContain('empty copy ready to edit');
    expect(s.hasWarnings).toBe(false);
  });
});
