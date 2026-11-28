import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from './dialog';

describe('Dialog — modal accessibility semantics', () => {
  it('exposes role=dialog + aria-modal while open, and hides the backdrop', () => {
    render(
      <Dialog open onOpenChange={() => {}}>
        <DialogContent>
          <DialogHeader><DialogTitle>كورس جديد</DialogTitle></DialogHeader>
          <DialogFooter>footer</DialogFooter>
        </DialogContent>
      </Dialog>,
    );

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByText('كورس جديد')).toBeInTheDocument();

    // The click-to-close backdrop must be hidden from assistive tech.
    const backdrop = dialog.parentElement?.querySelector('[aria-hidden="true"]');
    expect(backdrop).not.toBeNull();
  });

  it('renders nothing when closed', () => {
    render(
      <Dialog open={false} onOpenChange={() => {}}>
        <DialogContent><DialogTitle>hidden</DialogTitle></DialogContent>
      </Dialog>,
    );

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('closes when the backdrop is clicked', () => {
    const onOpenChange = vi.fn();
    render(
      <Dialog open onOpenChange={onOpenChange}>
        <DialogContent><DialogTitle>close me</DialogTitle></DialogContent>
      </Dialog>,
    );

    const backdrop = screen.getByRole('dialog').parentElement!.querySelector('[aria-hidden="true"]')!;
    fireEvent.click(backdrop);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
