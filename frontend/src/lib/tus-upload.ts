/**
 * Direct-to-Bunny resumable upload (TUS).
 *
 * The file never touches our server: the API only hands back a short-lived
 * signature, and the browser streams the bytes straight to Bunny. TUS makes the
 * upload resumable — a dropped connection continues from the last acknowledged
 * chunk instead of starting over. Keeping this in one small module lets the
 * queue component stay about state, not protocol details.
 */
import * as tus from 'tus-js-client';

/** Credentials the server signs for one video object (the API key never leaves it). */
export type TusCredentials = {
  endpoint: string;
  signature: string;
  expires: number;
  library_id: string;
  video_guid: string;
};

export type TusEventHandlers = {
  onProgress?: (percent: number, bytesUploaded: number, bytesTotal: number) => void;
  onSuccess?: () => void;
  onError?: (message: string) => void;
};

export type TusHandle = {
  /** Stop the upload and release the connection. */
  abort: () => void;
};

/** Backoff for transient network failures / 5xx: resumes, never restarts. */
export const TUS_RETRY_DELAYS = [0, 2000, 5000, 10000, 20000, 45000];

/**
 * A 4xx (except the temporary 409/423) will not fix itself — a bad signature or
 * an expired ticket must surface as an error instead of spinning forever.
 */
export function shouldRetryUpload(status: number | undefined): boolean {
  if (status === undefined) return true; // network error → retry
  if (status >= 400 && status < 500 && status !== 409 && status !== 423) return false;
  return true;
}

/**
 * Upload one file to Bunny. Returns a handle so the caller can abort.
 *
 * `resume` must be true only when the server re-signed the SAME video object
 * (its `reused` flag): resuming an upload URL that belongs to a different video
 * would send the remaining bytes to the wrong file.
 */
export async function uploadVideoDirect(
  file: File,
  creds: TusCredentials,
  handlers: TusEventHandlers = {},
  opts: { resume?: boolean } = {},
): Promise<TusHandle> {
  const upload = new tus.Upload(file, {
    endpoint: creds.endpoint,
    retryDelays: TUS_RETRY_DELAYS,
    headers: {
      AuthorizationSignature: creds.signature,
      AuthorizationExpire: String(creds.expires),
      VideoId: creds.video_guid,
      LibraryId: creds.library_id,
    },
    metadata: {
      filetype: file.type || 'video/mp4',
      title: file.name,
    },
    // Keep the upload URL so a reload + reselect can continue the same video.
    storeFingerprintForResuming: true,
    removeFingerprintOnSuccess: true,
    onProgress: (uploaded, total) => {
      handlers.onProgress?.(
        total > 0 ? Math.min(100, Math.round((uploaded / total) * 100)) : 0,
        uploaded,
        total,
      );
    },
    onSuccess: () => handlers.onSuccess?.(),
    onError: (err) => handlers.onError?.(err?.message || 'Upload failed'),
    onShouldRetry: (err) => shouldRetryUpload(err?.originalResponse?.getStatus()),
  });

  if (opts.resume) {
    try {
      const previous = await upload.findPreviousUploads();
      if (previous.length > 0) upload.resumeFromPreviousUpload(previous[0]);
    } catch {
      // Best-effort: if the stored upload can't be read, start fresh.
    }
  }

  upload.start();

  return {
    abort: () => {
      try {
        void upload.abort(true);
      } catch {
        // Already finished/aborted — nothing to do.
      }
    },
  };
}
