import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import ProtectedFileViewer from './protected-file-viewer';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

function ticket(body: Record<string, unknown>) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ user: { id: 'u1', name: 'سارة' } as any });
  global.fetch = vi.fn(() => ticket({
    url: '/api/v1/lessons/l1/file?mode=inline&token=abc',
    preview_url: '/api/v1/lessons/l1/file?mode=inline&token=abc',
    download_url: null,
    previewable: true,
    is_downloadable: false,
  })) as any;
});

describe('ProtectedFileViewer', () => {
  it('renders an in-app iframe for a previewable PDF with the viewer name tag', async () => {
    render(<ProtectedFileViewer file={{ name: 'notes.pdf', url: '/lessons/l1/file-ticket' }} />);

    await waitFor(() => {
      const frame = document.querySelector('iframe');
      expect(frame).toBeTruthy();
      expect(frame!.getAttribute('src')).toContain('token=abc');
      expect(frame!.getAttribute('src')).toContain('toolbar=0');
    });

    // Traceability tag carries the signed-in viewer's name.
    expect(screen.getByText(/سارة/)).toBeInTheDocument();
  });

  it('makes an honest statement instead of promising screen-record prevention', async () => {
    render(<ProtectedFileViewer file={{ name: 'notes.pdf', url: '/lessons/l1/file-ticket' }} />);

    await waitFor(() => expect(document.querySelector('iframe')).toBeTruthy());

    const text = document.body.textContent || '';
    expect(text).toContain('علامة للمتابعة');
    expect(text).not.toMatch(/لا يمكن تصوير الشاشة|يمنع التصوير/);
  });

  it('offers download only when the policy allows it', async () => {
    global.fetch = vi.fn(() => ticket({
      url: '/api/v1/lessons/l1/file?mode=attachment&token=abc',
      preview_url: null,
      download_url: '/api/v1/lessons/l1/file?mode=attachment&token=abc',
      previewable: false,
      is_downloadable: true,
    })) as any;

    render(<ProtectedFileViewer file={{ name: 'sheet.xlsx', url: '/lessons/l1/file-ticket' }} />);

    expect(await screen.findByRole('button', { name: /تحميل/ })).toBeInTheDocument();
    expect(document.querySelector('iframe')).toBeNull();
  });

  it('explains a locked file when neither preview nor download is allowed', async () => {
    global.fetch = vi.fn(() => ticket({
      url: null,
      preview_url: null,
      download_url: null,
      previewable: false,
      is_downloadable: false,
    })) as any;

    render(<ProtectedFileViewer file={{ name: 'locked.docx', url: '/lessons/l1/file-ticket' }} />);

    expect(await screen.findByText(/لا يمكن معاينته/)).toBeInTheDocument();
  });
});
