import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * The student renderer must treat the interactive question and the HTML
 * question as two INDEPENDENT types that just happen to share the same isolated
 * sandbox. This guards against a future change re-merging them (the regression
 * we are fixing): each type must render its own payload, and neither may be
 * handled by another type's branch.
 */
vi.mock('./interactive-html-question', () => ({
  InteractiveHtmlQuestion: ({ html }: { html: string }) => (
    <div data-testid="sandbox">{html}</div>
  ),
}));

import { QuestionRenderer } from './question-renderer';

const base = {
  exam_question_id: 'eq-1',
  question_id: 'q-1',
  points: 5,
  question_text: 'q',
};

describe('QuestionRenderer — sandboxed HTML types stay independent', () => {
  it('renders custom_html through the isolated sandbox', () => {
    render(
      <QuestionRenderer
        q={{ ...base, question_type: 'custom_html', metadata: { interactive: { html: '<h1>html-type</h1>' } } }}
        value=""
        onChange={() => {}}
      />,
    );

    expect(screen.getByTestId('sandbox')).toHaveTextContent('html-type');
  });

  it('renders interactive_html through the isolated sandbox, untouched', () => {
    render(
      <QuestionRenderer
        q={{ ...base, question_type: 'interactive_html', metadata: { interactive: { html: '<button>interactive-type</button>' } } }}
        value=""
        onChange={() => {}}
      />,
    );

    expect(screen.getByTestId('sandbox')).toHaveTextContent('interactive-type');
  });

  it('does not send any other type into the sandbox branch', () => {
    render(
      <QuestionRenderer
        q={{ ...base, question_type: 'multiple_choice', metadata: { options: [{ option_text: 'A', is_correct: true }] } }}
        value=""
        onChange={() => {}}
      />,
    );

    expect(screen.queryByTestId('sandbox')).toBeNull();
  });
});
