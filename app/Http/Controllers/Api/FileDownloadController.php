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
     * Authenticated endpoint: returns a short-lived signed file URL.
     * The raw file is never exposed — only signed, expiring, user-bound tokens.
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

        return response()->json([
            'url' => $this->protection->fileUrl($lesson, $user),
        ]);
    }

    /**
     * Media endpoint (no Authorization header possible for <a download>):
     * verifies the signed token, checks access rules and the teacher's download
     * policy, then streams the file. Sends Content-Disposition: attachment only
     * when the teacher allows downloads; otherwise serves inline (preview only).
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
        $disposition = $downloadsEnabled ? 'attachment' : 'inline';

        // Lesson files live on the private disk since the hardening pass.
        // Legacy rows stored before that still live on the public disk —
        // resolve whichever one actually holds the file.
        $private = Storage::disk('local');
        if ($private->exists($file->storage_path)) {
            $disk = $private;
        } else {
            $disk = Storage::disk('public');
        }

        return $disk->response($file->storage_path, $name, [
            'Content-Disposition' => $disposition.'; filename="'.rawurlencode($name).'"',
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
