import { describe, it, expect } from 'vitest';
import {
  addFiles, markDone, markFailed, markUploading, cancelPending, cancelAllPending,
  retryFailed, retryOne, nextPending, counts, MAX_FILE_BYTES, type QueueState,
} from './video-upload-queue';

const empty: QueueState = { items: [], rejected: [] };
let n = 0;
const makeId = () => `id-${++n}`;
const file = (name: string, size = 1024) =>
  ({ name, size, type: /\.(mp4|mov)$/i.test(name) ? 'video/mp4' : '' }) as unknown as File;

describe('video upload queue', () => {
  it('adds video files as pending and keeps their order', () => {
    const s = addFiles(empty, [file('a.mp4'), file('b.mov')], { remainingSlots: null, makeId });
    expect(s.items.map((i) => i.name)).toEqual(['a.mp4', 'b.mov']);
    expect(s.items.every((i) => i.status === 'pending')).toBe(true);
    expect(s.rejected).toEqual([]);
  });

  it('rejects non-video files without touching the queue', () => {
    const s = addFiles(empty, [file('notes.pdf'), file('clip.mp4')], { remainingSlots: null, makeId });
    expect(s.items.map((i) => i.name)).toEqual(['clip.mp4']);
    expect(s.rejected).toEqual([{ name: 'notes.pdf', reason: 'shortcut' }]);
  });

  it('rejects files larger than the 5 GB operational limit', () => {
    const s = addFiles(empty, [file('huge.mp4', MAX_FILE_BYTES + 1)], { remainingSlots: null, makeId });
    expect(s.items).toEqual([]);
    expect(s.rejected[0].reason).toBe('quota');
  });

  it('never queues more files than the remaining video slots', () => {
    const s = addFiles(empty, [file('1.mp4'), file('2.mp4'), file('3.mp4')], { remainingSlots: 2, makeId });
    expect(s.items).toHaveLength(2);
    expect(s.rejected).toEqual([{ name: '3.mp4', reason: 'quota' }]);
  });

  it('accounts for files already in the queue when applying the quota', () => {
    const first = addFiles(empty, [file('1.mp4')], { remainingSlots: 2, makeId });
    // Only one slot left after the first file.
    const second = addFiles(first, [file('2.mp4'), file('3.mp4')], { remainingSlots: 2, makeId });
    expect(second.items.map((i) => i.name)).toEqual(['1.mp4', '2.mp4']);
    expect(second.rejected).toEqual([{ name: '3.mp4', reason: 'quota' }]);
  });

  it('does not re-upload a succeeded file when retrying failures', () => {
    let s = addFiles(empty, [file('ok.mp4'), file('bad.mp4')], { remainingSlots: null, makeId });
    s = markDone(s, s.items[0].id);
    s = markFailed(s, s.items[1].id, 'network error');

    const retried = retryFailed(s);
    expect(retried.items[0].status).toBe('done');
    expect(retried.items[1].status).toBe('pending');
    expect(retried.items[1].error).toBeUndefined();
    // Only the failed item is eligible to start again.
    expect(nextPending(retried)?.name).toBe('bad.mp4');
  });

  it('cancels only not-yet-started items', () => {
    let s = addFiles(empty, [file('a.mp4'), file('b.mp4'), file('c.mp4')], { remainingSlots: null, makeId });
    s = markUploading(s, s.items[0].id);
    s = cancelPending(s, s.items[0].id); // uploading → not cancellable
    s = cancelPending(s, s.items[1].id);
    expect(s.items.map((i) => i.status)).toEqual(['uploading', 'canceled', 'pending']);

    const all = cancelAllPending(s);
    expect(all.items.map((i) => i.status)).toEqual(['uploading', 'canceled', 'canceled']);
  });

  it('retryOne only affects a failed item', () => {
    let s = addFiles(empty, [file('a.mp4')], { remainingSlots: null, makeId });
    s = retryOne(s, s.items[0].id); // pending stays pending
    expect(s.items[0].status).toBe('pending');
    s = markFailed(s, s.items[0].id, 'x');
    s = retryOne(s, s.items[0].id);
    expect(s.items[0].status).toBe('pending');
  });

  it('counts each status', () => {
    let s = addFiles(empty, [file('a.mp4'), file('b.mp4'), file('c.mp4')], { remainingSlots: null, makeId });
    s = markDone(s, s.items[0].id);
    s = markUploading(s, s.items[1].id);
    s = markFailed(s, s.items[2].id, 'x');
    expect(counts(s)).toMatchObject({ done: 1, uploading: 1, failed: 1, pending: 0, total: 3 });
  });

  it('keeps the asset id across a retry so the same video is reused', () => {
    let s = addFiles(empty, [file('a.mp4')], { remainingSlots: null, makeId });
    s = markFailed(s, s.items[0].id, 'x');
    s = { ...s, items: [{ ...s.items[0], assetId: 'asset-1' }] };
    s = retryOne(s, s.items[0].id);
    expect(s.items[0].assetId).toBe('asset-1');
  });
});
