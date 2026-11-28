import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

import ProtectedVideoPlayer from './protected-video-player';

function playbackResponse(playback: unknown) {
  return Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve({ playback }),
  } as any);
}

beforeEach(() => {
  localStorage.clear();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('ProtectedVideoPlayer — watermark coverage', () => {
  it('overlays the watermark on a YouTube iframe', async () => {
    global.fetch = vi.fn(() => playbackResponse({
      id: 'v1',
      type: 'external',
      provider: 'youtube',
      provider_video_id: 'dQw4w9WgXcQ',
      title: 'Lesson',
      protection: {},
      watermark: { enabled: true, text: 'سارة الطالبة', opacity: 0.35, speed: 'slow', size: '1rem' },
    })) as any;

    render(<ProtectedVideoPlayer videoId="v1" />);

    await waitFor(() => {
      expect(document.querySelector('iframe')?.getAttribute('src')).toContain('youtube.com/embed/dQw4w9WgXcQ');
    });
    expect(screen.getByText('سارة الطالبة')).toBeInTheDocument();
  });

  it('overlays the watermark on a Bunny HLS stream', async () => {
    global.fetch = vi.fn(() => playbackResponse({
      id: 'v2',
      type: 'provider',
      provider: 'bunny',
      status: 'ready',
      stream_url: 'https://cdn.example/stream.m3u8',
      protection: {},
      watermark: { enabled: true, text: 'أحمد محمد', opacity: 0.5, speed: 'medium', size: '1.3rem' },
    })) as any;

    render(<ProtectedVideoPlayer videoId="v2" />);

    expect(await screen.findByText('أحمد محمد')).toBeInTheDocument();
  });

  it('shows no watermark text when the teacher disabled it', async () => {
    global.fetch = vi.fn(() => playbackResponse({
      id: 'v3',
      type: 'external',
      provider: 'youtube',
      provider_video_id: 'dQw4w9WgXcQ',
      protection: {},
      watermark: { enabled: false },
    })) as any;

    render(<ProtectedVideoPlayer videoId="v3" />);

    await waitFor(() => expect(document.querySelector('iframe')).toBeTruthy());
    expect(document.querySelector('[data-watermark]')).toBeNull();
  });

  it('stamps the watermark with a traceable date/time', async () => {
    global.fetch = vi.fn(() => playbackResponse({
      id: 'v4',
      type: 'external',
      provider: 'youtube',
      provider_video_id: 'dQw4w9WgXcQ',
      protection: {},
      watermark: { enabled: true, text: 'سارة', opacity: 0.35, speed: 'slow', size: '1rem' },
    })) as any;

    render(<ProtectedVideoPlayer videoId="v4" />);

    const wm = await waitFor(() => {
      const el = document.querySelector('[data-watermark]');
      expect(el).toBeTruthy();
      return el as HTMLElement;
    });
    expect(wm.textContent).toContain('سارة');
    expect(wm.textContent).toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
  });

  it('never claims recording is impossible', async () => {
    global.fetch = vi.fn(() => playbackResponse({
      id: 'v5',
      type: 'external',
      provider: 'youtube',
      provider_video_id: 'dQw4w9WgXcQ',
      protection: {},
      watermark: { enabled: true, text: 'سارة', opacity: 0.35, speed: 'slow', size: '1rem' },
    })) as any;

    render(<ProtectedVideoPlayer videoId="v5" />);
    await waitFor(() => expect(document.querySelector('iframe')).toBeTruthy());

    // No absolute anti-recording / anti-screenshot promise anywhere in the UI.
    expect(document.body.textContent).not.toMatch(/يحظر التنزيل|يمنع التصوير|منع تسجيل/);
    expect(document.body.textContent).toContain('علامة مائية باسمك');
  });
});
