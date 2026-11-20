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
  /** The browser File to send. Kept on the item so a retry re-sends the bytes. */
  file?: File;
};

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
      file,
    });
    used += 1;
  }

  return { items, rejected };
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
