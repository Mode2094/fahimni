import { describe, it, expect } from 'vitest';
import {
  addFiles, markDone, markFailed, markUploading, cancelPending, cancelAllPending,
  retryFailed, retryOne, nextPending, counts, MAX_FILE_BYTES, MAX_PARALLEL,
  stableIdempotencyKey, nextPendingBatch, overallProgress, markCanceled, markAsset,
  uploadIdempotencyKey,
  type QueueState,
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

  // ─── Bulk: parallel, resume keys, aggregate progress ───

  it('derives one stable idempotency key per file (same file ⇒ same key)', () => {
    const a = { name: 'lecture.mp4', size: 10_000_000, lastModified: 1711111111 };
    expect(stableIdempotencyKey(a)).toBe(stableIdempotencyKey({ ...a }));
    expect(stableIdempotencyKey(a)).toMatch(/^vq-[0-9a-f]+$/);
    // A different file (different size) never collides in practice.
    expect(stableIdempotencyKey(a)).not.toBe(stableIdempotencyKey({ ...a, size: 10_000_001 }));
  });

  it('stamps a deterministic key on every queued item', () => {
    const f = { name: 'x.mp4', size: 2048, lastModified: 5, type: 'video/mp4' } as unknown as File;
    const s = addFiles(empty, [f], { remainingSlots: null, makeId });
    expect(s.items[0].key).toBe(stableIdempotencyKey(f));
  });

  it('hands out at most MAX_PARALLEL pending items so the pool is bounded', () => {
    const s = addFiles(empty, [file('1.mp4'), file('2.mp4'), file('3.mp4'), file('4.mp4')], { remainingSlots: null, makeId });
    expect(nextPendingBatch(s, MAX_PARALLEL).map((i) => i.name)).toEqual(['1.mp4', '2.mp4', '3.mp4']);
    expect(nextPendingBatch(s, 0)).toEqual([]);
    expect(MAX_PARALLEL).toBeGreaterThanOrEqual(2);
    expect(MAX_PARALLEL).toBeLessThanOrEqual(3);
  });

  it('reports overall progress across every file, ignoring canceled ones', () => {
    let s = addFiles(empty, [file('a.mp4'), file('b.mp4'), file('c.mp4')], { remainingSlots: null, makeId });
    expect(overallProgress(s)).toEqual({ percent: 0, settled: 0, total: 3 });
    s = markDone(s, s.items[0].id); // 100
    s = { ...s, items: s.items.map((i, idx) => (idx === 1 ? { ...i, progress: 50 } : i)) }; // 50
    s = markCanceled(s, s.items[2].id); // excluded
    expect(overallProgress(s)).toEqual({ percent: 75, settled: 1, total: 2 });
  });

  it('cancels an active item and records its asset id for cleanup', () => {
    let s = addFiles(empty, [file('a.mp4')], { remainingSlots: null, makeId });
    s = markAsset(s, s.items[0].id, 'asset-9');
    s = markUploading(s, s.items[0].id);
    s = markCanceled(s, s.items[0].id);
    expect(s.items[0].status).toBe('canceled');
    expect(s.items[0].assetId).toBe('asset-9');
    // A canceled item is never picked up again.
    expect(nextPendingBatch(s, MAX_PARALLEL)).toEqual([]);
  });

  // ─── Idempotency is per operation, not per file ───
  // Regression: two queue items for the same file shared one server key, so the
  // second upload reused the first's asset and its lesson attach failed (502).

  it('gives two queue items for the same file distinct server keys', () => {
    const f = { name: 'same.mp4', size: 4096, lastModified: 9 } as unknown as File;
    const s = addFiles(empty, [f, f], { remainingSlots: null, makeId });
    expect(s.items).toHaveLength(2);
    expect(s.items[0].key).toBe(s.items[1].key); // same file ⇒ same file key
    expect(uploadIdempotencyKey(s.items[0])).not.toBe(uploadIdempotencyKey(s.items[1]));
  });

  it('scopes the key to the destination lesson when uploading into an existing lesson', () => {
    const item = { id: 'id-1', key: 'vq-abc' };
    expect(uploadIdempotencyKey(item)).toBe('vq-abc-id-1');
    expect(uploadIdempotencyKey(item, { destination: 'create' })).toBe('vq-abc-id-1');
    expect(uploadIdempotencyKey(item, { destination: 'existing', lessonId: 'L1' })).toBe('vq-abc-id-1-L1');
    // A different target lesson never reuses the other lesson's key.
    expect(uploadIdempotencyKey(item, { destination: 'existing', lessonId: 'L2' })).not.toBe(
      uploadIdempotencyKey(item, { destination: 'existing', lessonId: 'L1' }),
    );
  });
});
