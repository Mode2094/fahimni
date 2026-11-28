/**
 * Pure state machine for the multi-video upload queue.
 *
 * Kept free of any network/browser code so the tricky parts — enforcing the
 * plan's video quota, never re-uploading a file that already succeeded, and
 * cancelling only what has not started — are unit-testable.
 */

export type UploadStatus = 'pending' | 'uploading' | 'processing' | 'done' | 'failed' | 'canceled';

export type QueueItem = {
  /** Stable client id (survives retries so we never double-count a file). */
  id: string;
  name: string;
  size: number;
  status: UploadStatus;
  progress: number;
  error?: string;
  /** Provider asset id, kept across retries so a retry reuses the same video. */
  assetId?: string;
  /** Lesson chosen for this file (existing or created). */
  lessonId?: string;
  /**
   * Deterministic idempotency key derived from the file itself (name+size+mtime).
   * Re-adding the same file after a reload gets the same key, so the server
   * reuses the same video and TUS can resume instead of creating a duplicate.
   */
  key?: string;
  /** The browser File to send. Kept on the item so a retry re-sends the bytes. */
  file?: File;
};

/** How many videos upload at once — bounded to protect the browser + connection. */
export const MAX_PARALLEL = 3;

export type QueueState = {
  items: QueueItem[];
  /** Files rejected because they would exceed the plan's remaining quota. */
  rejected: { name: string; reason: 'quota' | 'shortcut' }[];
};

export const MAX_FILE_BYTES = 5 * 1024 * 1024 * 1024; // 5 GB (nginx/PHP limit)

export type AddOptions = {
  /** Remaining video slots on the teacher's whole platform, or null if unknown. */
  remainingSlots: number | null;
  /** Count of items already counted against the quota (done + uploading + processing + pending). */
  alreadyCounted?: number;
  makeId: () => string;
};

const isVideoFile = (f: { name: string; type?: string }) =>
  /\.(mp4|webm|mov|mkv|avi|m4v)$/i.test(f.name) || (f.type || '').startsWith('video/');

/**
 * A stable key for a file: same name, size and mtime ⇒ same key. It never
 * changes across retries or reloads, so the same file maps to one video.
 */
export function stableIdempotencyKey(f: { name: string; size: number; lastModified?: number }): string {
  const raw = `${f.name}|${f.size}|${f.lastModified ?? 0}`;
  // Small, dependency-free hash (FNV-1a) — good enough to dedupe a burst of
  // files from one teacher's device, not a security primitive.
  let h = 0x811c9dc5;
  for (let i = 0; i < raw.length; i++) {
    h ^= raw.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return `vq-${(h >>> 0).toString(16)}`;
}

/**
 * Add files to the queue. Oversized/non-video files are rejected, and — when we
 * know the remaining slots — extra files beyond the quota are rejected up front
 * (never silently dropped, never sent to the server to fail there).
 */
export function addFiles(state: QueueState, files: File[], opts: AddOptions): QueueState {
  const items = [...state.items];
  const rejected = [...state.rejected];
  // Count everything not already finished as consuming a slot, so a burst of
  // files cannot all "fit" and then blow past the plan limit on the server.
  let used = opts.alreadyCounted
    ?? items.filter((i) => i.status !== 'failed' && i.status !== 'canceled').length;
  const limitReached = () => opts.remainingSlots !== null && used >= opts.remainingSlots;

  for (const file of files) {
    if (!isVideoFile(file)) {
      rejected.push({ name: file.name, reason: 'shortcut' });
      continue;
    }
    if (file.size > MAX_FILE_BYTES) {
      rejected.push({ name: file.name, reason: 'quota' });
      continue;
    }
    if (limitReached()) {
      rejected.push({ name: file.name, reason: 'quota' });
      continue;
    }
    items.push({
      id: opts.makeId(),
      name: file.name,
      size: file.size,
      status: 'pending',
      progress: 0,
      key: stableIdempotencyKey(file),
      file,
    });
    used += 1;
  }

  return { items, rejected };
}

/**
 * The server idempotency key for ONE upload operation.
 *
 * The file-level `key` dedupes a file re-added after a reload (TUS resumes, no
 * duplicate). But two distinct queue items for the same file — the same clip
 * added twice, or a corrected re-upload — must not share a key: the server
 * would reuse the first item's provider asset for the second, and the leftover
 * lesson binding failed. The queue item id (unique per operation) disambiguates
 * them; when uploading straight into an existing lesson, that lesson is folded
 * in too so the same file can target two lessons independently.
 */
export function uploadIdempotencyKey(
  item: Pick<QueueItem, 'key' | 'id'>,
  opts: { destination?: 'create' | 'existing'; lessonId?: string } = {},
): string {
  const base = `${item.key || ''}-${item.id}`;
  return opts.destination === 'existing' && opts.lessonId ? `${base}-${opts.lessonId}` : base;
}

export function markUploading(state: QueueState, id: string): QueueState {
  return patch(state, id, { status: 'uploading', progress: 0, error: undefined });
}

export function markProgress(state: QueueState, id: string, progress: number): QueueState {
  return patch(state, id, { progress: Math.min(100, Math.max(0, Math.round(progress))) });
}

export function markProcessing(state: QueueState, id: string, assetId?: string): QueueState {
  return patch(state, id, { status: 'processing', progress: 100, assetId, error: undefined });
}

export function markDone(state: QueueState, id: string, lessonId?: string, assetId?: string): QueueState {
  return patch(state, id, { status: 'done', progress: 100, lessonId, assetId, error: undefined });
}

export function markFailed(state: QueueState, id: string, error: string): QueueState {
  return patch(state, id, { status: 'failed', error });
}

/** Remember the server-side asset id as soon as the ticket returns it. */
export function markAsset(state: QueueState, id: string, assetId: string): QueueState {
  return patch(state, id, { assetId });
}

/** Cancel an item in any active state (the caller aborts a live upload). */
export function markCanceled(state: QueueState, id: string): QueueState {
  return patch(state, id, { status: 'canceled' });
}

/** Cancel only not-yet-started items; a partial upload is left to finish/fail. */
export function cancelPending(state: QueueState, id: string): QueueState {
  return mapItem(state, id, (i) =>
    i.status === 'pending' ? { ...i, status: 'canceled' as UploadStatus } : i,
  );
}

export function cancelAllPending(state: QueueState): QueueState {
  return {
    ...state,
    items: state.items.map((i) =>
      i.status === 'pending' ? { ...i, status: 'canceled' as UploadStatus } : i,
    ),
  };
}

/** Re-queue failed items. Successful items are untouched (never re-uploaded). */
export function retryFailed(state: QueueState): QueueState {
  return {
    ...state,
    items: state.items.map((i) =>
      i.status === 'failed' ? { ...i, status: 'pending' as UploadStatus, progress: 0, error: undefined } : i,
    ),
    rejected: state.rejected,
  };
}

export function retryOne(state: QueueState, id: string): QueueState {
  return mapItem(state, id, (i) =>
    i.status === 'failed' ? { ...i, status: 'pending' as UploadStatus, progress: 0, error: undefined } : i,
  );
}

/** The next item that should start, or null when nothing is pending. */
export function nextPending(state: QueueState): QueueItem | null {
  return state.items.find((i) => i.status === 'pending') ?? null;
}

/** Up to `limit` pending items, so several videos can upload in parallel. */
export function nextPendingBatch(state: QueueState, limit: number): QueueItem[] {
  if (limit <= 0) return [];
  return state.items.filter((i) => i.status === 'pending').slice(0, limit);
}

/**
 * Overall batch progress: the mean of each unfinished/finished item's own
 * progress, ignoring canceled ones (they are no longer part of the batch).
 */
export function overallProgress(state: QueueState): { percent: number; settled: number; total: number } {
  const counted = state.items.filter((i) => i.status !== 'canceled');
  if (counted.length === 0) return { percent: 0, settled: 0, total: 0 };
  const sum = counted.reduce((acc, i) => acc + (i.status === 'done' ? 100 : i.progress), 0);
  const settled = counted.filter((i) => i.status === 'done' || i.status === 'failed').length;
  return { percent: Math.round(sum / counted.length), settled, total: counted.length };
}

export function counts(state: QueueState) {
  const by = (s: UploadStatus) => state.items.filter((i) => i.status === s).length;
  return {
    pending: by('pending'),
    uploading: by('uploading'),
    processing: by('processing'),
    done: by('done'),
    failed: by('failed'),
    canceled: by('canceled'),
    total: state.items.length,
  };
}

export const humanBytes = (bytes: number) => {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
  if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${bytes} B`;
};

function patch(state: QueueState, id: string, changes: Partial<QueueItem>): QueueState {
  return mapItem(state, id, (i) => ({ ...i, ...changes }));
}

function mapItem(state: QueueState, id: string, fn: (i: QueueItem) => QueueItem): QueueState {
  return { ...state, items: state.items.map((i) => (i.id === id ? fn(i) : i)) };
}
