import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

import AssistantChat from './assistant-chat';
import { useAskMe } from '@/store/askme';

// The component reads the assistant endpoint through the store, which imports
// it at module load — mock it before the store is exercised.
const { ask } = vi.hoisted(() => ({ ask: vi.fn() }));

vi.mock('@/services/endpoints', () => ({
  assistant: { ask },
}));

beforeEach(() => {
  ask.mockReset();
  useAskMe.setState({ open: false, seen: false, messages: [], asking: false, error: null });
});

describe('AssistantChat (teacher sidebar assistant)', () => {
  it('renders the platform assistant with its suggestions', async () => {
    render(<AssistantChat />);

    expect(await screen.findByRole('heading', { name: 'اسألني عن المنصة' })).toBeTruthy();
    // Welcome message seeded on mount + at least one quick suggestion.
    expect(screen.getByText('كيف أعمل كورس؟')).toBeTruthy();
  });

  it('sends a question and shows the assistant answer', async () => {
    ask.mockResolvedValue({ answer: 'جواب تجريبي من المساعد' });

    render(<AssistantChat />);
    await screen.findByRole('heading', { name: 'اسألني عن المنصة' });

    const input = screen.getByPlaceholderText('اكتب سؤالك عن المنصة...');
    fireEvent.change(input, { target: { value: 'كيف أعمل كورس؟' } });
    fireEvent.keyDown(input, { key: 'Enter' });

    await waitFor(() => expect(ask).toHaveBeenCalledWith('كيف أعمل كورس؟', expect.any(String)));
    expect(await screen.findByText('جواب تجريبي من المساعد')).toBeTruthy();
  });

  it('surfaces a graceful error instead of failing silently', async () => {
    ask.mockRejectedValue(new Error('boom'));

    render(<AssistantChat />);
    await screen.findByRole('heading', { name: 'اسألني عن المنصة' });

    const input = screen.getByPlaceholderText('اكتب سؤالك عن المنصة...');
    fireEvent.change(input, { target: { value: 'سؤال' } });
    fireEvent.keyDown(input, { key: 'Enter' });

    expect(await screen.findByText(/حدث خطأ أثناء معالجة سؤالك/)).toBeTruthy();
  });
});
