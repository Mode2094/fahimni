import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import CourseGroupsManager from './course-groups';

/**
 * In-course groups manager. These tests lock the real behaviours: it reads the
 * course's own candidates, and assigning a lesson / exam / member writes to the
 * right endpoint (never cloning the course).
 */
const groupsPayload = {
  groups: [
    { id: 'g1', course_id: 'c1', name: 'أول ثانوي — أ', is_active: true, members_count: 1, lessons_count: 0, exams_count: 0 },
  ],
  students: [{ id: 's1', name: 'طالب واحد', email: 'a@b.c' }],
  lessons: [{ id: 'l1', title: 'درس أول' }, { id: 'l2', title: 'درس عام' }],
  exams: [{ id: 'e1', title: 'اختبار الوحدة', is_published: true }],
};

const detail = { group: { ...groupsPayload.groups[0], member_ids: [], lesson_ids: [], exam_ids: [] } };
const resultsPayload = {
  group: { id: 'g1', name: 'أول ثانوي — أ', members_count: 1, submitted_attempts: 1, average_percentage: 90, pass_rate: 100 },
  all: { submitted_attempts: 2, average_percentage: 70, pass_rate: 50 },
  per_student: [{ user_id: 's1', name: 'طالب واحد', attempts: 1, average_percentage: 90 }],
};

let calls: { url: string; method: string; body: any }[] = [];

function mockFetch() {
  calls = [];
  global.fetch = vi.fn(async (input: any, init: any = {}) => {
    const url = String(input);
    const method = (init.method || 'GET').toUpperCase();
    calls.push({ url, method, body: init.body ? JSON.parse(init.body) : null });
    const ok = (json: any) => ({ ok: true, status: 200, json: async () => json } as any);
    if (url.endsWith('/groups') && method === 'GET') return ok(groupsPayload);
    if (url.includes('/results')) return ok(resultsPayload);
    if (/course-groups\/[^/]+$/.test(url) && method === 'GET') return ok(detail);
    if (url.includes('/lessons') && method === 'PUT') return ok({ group: detail.group });
    if (url.includes('/exams') && method === 'PUT') return ok({ group: detail.group });
    if (url.includes('/members') && method === 'PUT') return ok({ group: detail.group });
    return ok({});
  });
}

describe('CourseGroupsManager', () => {
  beforeEach(() => { localStorage.setItem('tenant_id', 't1'); mockFetch(); });
  afterEach(() => { vi.restoreAllMocks(); });

  it('lists the course groups and its real candidates', async () => {
    render(<CourseGroupsManager open onOpenChange={() => {}} courseId="c1" />);
    await waitFor(() => expect(screen.getAllByText('أول ثانوي — أ').length).toBeGreaterThan(0));
    await waitFor(() => expect(screen.getByText('طالب واحد')).toBeTruthy());
  });

  it('assigns a lesson to a group through the lessons endpoint', async () => {
    render(<CourseGroupsManager open onOpenChange={() => {}} courseId="c1" />);
    await waitFor(() => expect(screen.getAllByText('أول ثانوي — أ').length).toBeGreaterThan(0));

    fireEvent.click(screen.getByText('الدروس المشتركة'));
    const lesson = await screen.findByText('درس أول');
    const checkbox = lesson.closest('label')!.querySelector('input')!;
    fireEvent.click(checkbox);

    await waitFor(() => {
      const put = calls.find((c) => c.url.includes('/course-groups/g1/lessons') && c.method === 'PUT');
      expect(put).toBeTruthy();
      expect(put!.body.lesson_ids).toContain('l1');
    });
  });

  it('fetches per-group results with the whole-course comparison', async () => {
    render(<CourseGroupsManager open onOpenChange={() => {}} courseId="c1" />);
    await waitFor(() => expect(screen.getAllByText('أول ثانوي — أ').length).toBeGreaterThan(0));

    fireEvent.click(screen.getByText('النتائج'));
    await waitFor(() => {
      expect(calls.some((c) => c.url.includes('/course-groups/g1/results'))).toBe(true);
    });
    await waitFor(() => expect(screen.getAllByText(/90%/).length).toBeGreaterThan(0));
    expect(screen.getAllByText(/70%/).length).toBeGreaterThan(0);
  });
});
