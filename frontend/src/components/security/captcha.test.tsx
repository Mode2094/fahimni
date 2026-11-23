import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Captcha } from './captcha';

const fetchMock = vi.fn();

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
});

function jsonOnce(body: unknown) {
  fetchMock.mockResolvedValueOnce({ ok: true, json: async () => body } as any);
}

describe('Captcha', () => {
  it('renders nothing when the platform has captcha disabled', async () => {
    jsonOnce({ enabled: false });

    const { container } = render(<Captcha value="" onChange={() => {}} onId={() => {}} />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/captcha', expect.anything()));
    expect(container).toBeEmptyDOMElement();
  });

  it('shows the challenge and reports its id when enabled', async () => {
    jsonOnce({ enabled: true, question: '2 + 3 = ?', captcha_id: 'cap-1' });
    const onId = vi.fn();

    render(<Captcha value="" onChange={() => {}} onId={onId} locale="en" />);

    expect(await screen.findByText('2 + 3 = ?')).toBeInTheDocument();
    await waitFor(() => expect(onId).toHaveBeenCalledWith('cap-1'));
    // The answer input is properly labelled for assistive tech.
    expect(screen.getByLabelText('Security check')).toBeInTheDocument();
  });

  it('propagates the typed answer and refreshes on demand', async () => {
    jsonOnce({ enabled: true, question: '2 + 3 = ?', captcha_id: 'cap-1' });
    const onChange = vi.fn();
    const user = userEvent.setup();

    render(<Captcha value="" onChange={onChange} onId={() => {}} locale="en" />);

    const input = await screen.findByLabelText('Security check');
    await user.type(input, '5');
    expect(onChange).toHaveBeenCalled();

    // A refresh mints a new challenge.
    jsonOnce({ enabled: true, question: '4 + 1 = ?', captcha_id: 'cap-2' });
    await user.click(screen.getByRole('button', { name: 'Refresh the security question' }));
    expect(await screen.findByText('4 + 1 = ?')).toBeInTheDocument();
  });
});
