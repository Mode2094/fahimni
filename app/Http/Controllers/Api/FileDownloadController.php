<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\FileProtectionService;
use App\Support\StoragePathGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileDownloadController extends Controller
{
    public function __construct(
        private readonly FileProtectionService $protection,
    ) {}

    /**
     * Authenticated endpoint: returns short-lived signed file URLs.
     *
     * Two URLs are handed out because the in-app viewer and a real download
     * need opposite Content-Disposition values: `preview_url` is inline-only
     * and is present only for formats a browser can render; `download_url` is
     * an attachment and is present only when the file is downloadable and the
     * tenant still allows downloads. Either way the raw file is never exposed
     * — only signed, expiring, user-bound tokens. `url` stays for backward
     * compatibility (prefer preview, fall back to download).
     */
    public function ticket(Request $request, string $lessonId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lesson = Lesson::with(['section.course', 'contents.file'])
            ->findOrFail($lessonId);

        if (! $this->protection->canAccess($lesson, $user)) {
            return response()->json(['message' => 'No access to this file.'], 403);
        }

        $file = $lesson->contents->where('content_type', 'file')->first()?->file;

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $settings = TenantSetting::where('tenant_id', $lesson->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $previewable = $this->protection->isPreviewable((string) $file->extension);
        $downloadable = $downloadsEnabled && (bool) $file->is_downloadable;

        $previewUrl = $previewable
            ? $this->protection->fileUrl($lesson, $user, FileProtectionService::DEFAULT_TTL, 'inline')
            : null;
        $downloadUrl = $downloadable
            ? $this->protection->fileUrl($lesson, $user, FileProtectionService::DEFAULT_TTL, 'attachment')
            : null;

        return response()->json([
            'url' => $previewUrl ?? $downloadUrl,
            'preview_url' => $previewUrl,
            'download_url' => $downloadUrl,
            'previewable' => $previewable,
            'is_downloadable' => $downloadable,
        ]);
    }

    /**
     * Media endpoint (no Authorization header possible for <iframe>/<a>):
     * verifies the signed token, checks access rules and the teacher's download
     * policy, then streams the file.
     *
     * `mode=inline`  → render only, and only for a previewable format.
     * `mode=attachment` → a real download, only when allowed.
     * Any other value keeps the legacy behaviour (attachment when downloads are
     * enabled, inline otherwise). Permissions are always re-checked here — the
     * mode is a hint, never an authorization.
     */
    public function download(Request $request, string $lessonId): StreamedResponse|JsonResponse
    {
        $lesson = Lesson::with(['section.course', 'contents.file'])
            ->findOrFail($lessonId);

        $file = $lesson->contents->where('content_type', 'file')->first()?->file;

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $token = $request->query('token', '');
        $user = $this->protection->resolveUserFromToken($token, $lesson);

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired file token.'], 403);
        }

        if (! $this->protection->canAccess($lesson, $user)) {
            return response()->json(['message' => 'No access to this file.'], 403);
        }

        // Guard against traversal: the stored path must live under the
        // lesson-file or upload roots, never `..`-escaped onto the disk root.
        if (! StoragePathGuard::isSafe($file->storage_path, ['lesson-files', 'uploads'])) {
            return response()->json(['message' => 'Invalid file path.'], 404);
        }

        // Tenant-owned files must live under the lesson's own tenant folder.
        if (str_starts_with((string) $file->storage_path, 'lesson-files/')
            && ! str_starts_with((string) $file->storage_path, 'lesson-files/'.$lesson->tenant_id.'/')) {
            return response()->json(['message' => 'Invalid file path.'], 404);
        }

        $settings = TenantSetting::where('tenant_id', $lesson->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $previewable = $this->protection->isPreviewable((string) $file->extension);
        $downloadable = $downloadsEnabled && (bool) $file->is_downloadable;

        switch ($request->query('mode')) {
            case 'inline':
                if (! $previewable) {
                    return response()->json(['message' => 'This file type cannot be previewed.'], 403);
                }
                $disposition = 'inline';
                break;

            case 'attachment':
                if (! $downloadable) {
                    return response()->json(['message' => 'Downloads are disabled for this file.'], 403);
                }
                $disposition = 'attachment';
                break;

            default:
                // Legacy callers: attachment only when a download is allowed,
                // inline for a previewable file, and refused when neither.
                if ($downloadable) {
                    $disposition = 'attachment';
                } elseif ($previewable) {
                    $disposition = 'inline';
                } else {
                    return response()->json(['message' => 'This file cannot be viewed or downloaded.'], 403);
                }
        }

        // Lesson files live on the private disk since the hardening pass.
        // Legacy rows stored before that still live on the public disk —
        // resolve whichever one actually holds the file.
        $private = Storage::disk('local');
        if ($private->exists($file->storage_path)) {
            $disk = $private;
        } else {
            $disk = Storage::disk('public');
        }

        if (! $disk->exists($file->storage_path)) {
            return response()->json(['message' => 'File missing.'], 404);
        }

        $name = $file->original_name ?? basename($file->storage_path);

        return $disk->response($file->storage_path, $name, [
            'Content-Disposition' => $disposition.'; filename="'.rawurlencode($name).'"',
            'Content-Type' => $this->resolveMimeType($file),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A stored MIME type wins; otherwise fall back to the extension so a PDF
     * still renders inline instead of being served as octet-stream.
     */
    private function resolveMimeType($file): string
    {
        $mime = (string) ($file->mime_type ?? '');

        if ($mime !== '' && $mime !== 'application/octet-stream') {
            return $mime;
        }

        return match (strtolower((string) $file->extension)) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => $mime !== '' ? $mime : 'application/octet-stream',
        };
    }
}
