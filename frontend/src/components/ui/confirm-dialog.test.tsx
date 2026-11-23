import { describe, it, expect, vi } from 'vitest';
import { render, screen, act, waitFor, fireEvent } from '@testing-library/react';
import { ConfirmDialogHost, confirmDialog, promptDialog } from './confirm-dialog';

describe('ConfirmDialogHost — accessible replacement for window.confirm/prompt', () => {
  it('resolves true when confirmed and shows the description', async () => {
    render(<ConfirmDialogHost />);

    let result: boolean | undefined;
    await act(async () => {
      void confirmDialog({ description: 'حذف هذا العنصر؟' }).then((v) => {
        result = v;
      });
    });

    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByText('حذف هذا العنصر؟')).toBeInTheDocument();

    await act(async () => {
      screen.getByRole('button', { name: 'تأكيد' }).click();
    });

    await waitFor(() => expect(result).toBe(true));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('resolves false when cancelled via the cancel button', async () => {
    render(<ConfirmDialogHost />);

    let result: boolean | undefined;
    await act(async () => {
      void confirmDialog({ description: 'x' }).then((v) => {
        result = v;
      });
    });
    await screen.findByRole('dialog');

    await act(async () => {
      screen.getByRole('button', { name: 'إلغاء' }).click();
    });

    await waitFor(() => expect(result).toBe(false));
  });

  it('cancels on Escape', async () => {
    render(<ConfirmDialogHost />);

    let result: boolean | undefined;
    await act(async () => {
      void confirmDialog({ description: 'x' }).then((v) => {
        result = v;
      });
    });
    await screen.findByRole('dialog');

    await act(async () => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });

    await waitFor(() => expect(result).toBe(false));
  });

  it('returns the typed value from promptDialog and seeds the default', async () => {
    render(<ConfirmDialogHost />);

    let result: string | null | undefined;
    await act(async () => {
      void promptDialog({ inputLabel: 'رابط الاجتماع', defaultValue: 'https://x' }).then((v) => {
        result = v;
      });
    });

    const input = (await screen.findByLabelText('رابط الاجتماع')) as HTMLInputElement;
    expect(input.value).toBe('https://x');

    await act(async () => {
      fireEvent.change(input, { target: { value: 'https://meet.example/abc' } });
    });
    await act(async () => {
      screen.getByRole('button', { name: 'تأكيد' }).click();
    });

    await waitFor(() => expect(result).toBe('https://meet.example/abc'));
  });

  it('returns null when a prompt is cancelled', async () => {
    render(<ConfirmDialogHost />);

    let result: string | null | undefined;
    await act(async () => {
      void promptDialog({ inputLabel: 'اسم' }).then((v) => {
        result = v;
      });
    });
    await screen.findByRole('dialog');

    await act(async () => {
      screen.getByRole('button', { name: 'إلغاء' }).click();
    });

    await waitFor(() => expect(result).toBeNull());
  });

  it('disables confirm for a required empty prompt', async () => {
    render(<ConfirmDialogHost />);

    await act(async () => {
      void promptDialog({ inputLabel: 'اسم', required: true });
    });
    await screen.findByRole('dialog');

    expect(screen.getByRole('button', { name: 'تأكيد' })).toBeDisabled();
  });

  it('falls back to the native confirm when no host is mounted', async () => {
    const nativeConfirm = vi.fn().mockReturnValue(true);
    vi.stubGlobal('confirm', nativeConfirm);

    await expect(confirmDialog({ description: 'native?' })).resolves.toBe(true);
    expect(nativeConfirm).toHaveBeenCalledWith('native?');
  });
});
