<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Server-side file validation that never trusts the client.
 *
 * Laravel's `mimes`/`mimetypes` rules both rely on client-declared metadata
 * (filename extension / $_FILES content-type), so a crafted file can pass
 * them with an HTML payload. This helper sniffs the actual bytes with
 * finfo and rejects:
 *  - content whose real MIME is HTML/XML/SVG/JavaScript (stored XSS)
 *  - double extensions (x.php.png)
 *  - markup smuggled inside text files
 */
class UploadValidator
{
    private const VIDEO_MIMES = [
        'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v',
        'video/x-matroska', 'video/ogg', 'application/octet-stream',
    ];

    private const IMAGE_MIMES = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp', 'image/x-icon',
    ];

    private const DOCUMENT_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'text/plain', 'text/markdown',
        'application/zip', 'application/x-zip-compressed',
        'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/aac',
    ];

    private const FORBIDDEN_HTML_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'text/javascript', 'application/javascript', 'application/x-javascript',
        'application/xml', 'text/xml', 'application/json',
    ];

    private const SNIFF_BYTES = 8192;

    /**
     * Validate an uploaded video's actual content.
     *
     * @throws \RuntimeException with a user-safe message
     */
    public static function video(UploadedFile $file): void
    {
        self::rejectBadName($file);

        $sniffed = self::sniff($file);

        if (! in_array($sniffed['mime'], self::VIDEO_MIMES, true) || self::isForbiddenMime($sniffed['mime'])) {
            throw new \RuntimeException('The file is not a valid video.');
        }
    }

    /**
     * Validate a generic upload (images, documents, archives, audio).
     *
     * When $allowSvg is true the upload is treated as platform branding
     * (super-admin, no tenant): an SVG is accepted, sanitized and written
     * back to the temp file before it is stored.
     *
     * @throws \RuntimeException with a user-safe message
     */
    public static function generic(UploadedFile $file, bool $allowSvg = false): void
    {
        self::rejectBadName($file, $allowSvg);

        $sniffed = self::sniff($file);

        $allowed = array_merge(self::IMAGE_MIMES, self::DOCUMENT_MIMES, self::VIDEO_MIMES);
        $isSvg = $sniffed['mime'] === 'image/svg+xml';

        if (! in_array($sniffed['mime'], $allowed, true) || self::isForbiddenMime($sniffed['mime'])) {
            // SVG is normally a stored-XSS vehicle and stays forbidden. The
            // single exception: the sanitized super-admin branding upload.
            if (! ($allowSvg && $isSvg)) {
                throw new \RuntimeException('The file type is not allowed.');
            }
        }

        if ($isSvg) {
            self::sanitizeSvg($file);

            return;
        }

        // Only text-based files can serve stored markup to a browser. Binary
        // containers (PNG/JPEG/WebP, PDF, archives) legitimately embed markup
        // strings inside metadata (C2PA/JUMBF, XMP, Exif, PNG tEXt chunks).
        if (str_starts_with($sniffed['mime'], 'text/')) {
            self::rejectMarkupInText($sniffed['head']);
        }
    }

    private static function rejectBadName(UploadedFile $file, bool $allowSvg = false): void
    {
        $name = (string) $file->getClientOriginalName();

        // .svg is only accepted on the sanitized branding path (super admin).
        if ($allowSvg) {
            $name = (string) preg_replace('/\.svg$/i', '', $name);
        }

        // Double extension: "report.php.pdf" or any executable extension
        // hidden anywhere in the name.
        if (preg_match('/\.(php\d?|phtml|phar|sh|pl|py|cgi|asp|jsp|htaccess|js|html?|xml|svg)(\.|$)/i', $name) === 1) {
            throw new \RuntimeException('Invalid file name.');
        }
    }

    /**
     * Strip everything script-capable from an SVG before it reaches the
     * public disk: DOCTYPE, <script> blocks, event-handler attributes,
     * javascript: URLs and the HTML-embedding elements (foreignObject,
     * iframe, object, embed).
     */
    private static function sanitizeSvg(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if (! $path || ! is_file($path)) {
            throw new \RuntimeException('Could not inspect the uploaded file.');
        }

        $svg = (string) file_get_contents($path);

        // DOCTYPE is removed including any internal subset ([...]) so
        // <!ENTITY ...> declarations cannot survive.
        $svg = (string) preg_replace('/<!DOCTYPE[^>[]*(\[[^\]]*\])?[^>]*>/is', '', $svg);
        $svg = (string) preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $svg);
        $svg = (string) preg_replace('/<script\b[^>]*\/>/i', '', $svg);
        $svg = (string) preg_replace('/<(foreignObject|iframe|object|embed)\b[^>]*>.*?<\/\1\s*>/is', '', $svg);
        $svg = (string) preg_replace('/<(foreignObject|iframe|object|embed)\b[^>]*\/>/i', '', $svg);
        $svg = (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);

        // URL-bearing attributes: decode XML/HTML entities first so an
        // encoded `&#x6a;avascript:` cannot slip past a literal match.
        $svg = (string) preg_replace_callback(
            '/(href|xlink:href|action)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/is',
            static function (array $m): string {
                $raw = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                $decoded = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                if (preg_match('/^(javascript|vbscript|data:text\/html|data:image\/svg)/i', $decoded) === 1) {
                    return $m[1].'=""';
                }

                return $m[0];
            },
            $svg
        );

        if (file_put_contents($path, $svg) === false) {
            throw new \RuntimeException('Could not process the uploaded file.');
        }
    }

    private static function sniff(UploadedFile $file): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');

        if ($tmp === false) {
            throw new \RuntimeException('Could not inspect the uploaded file.');
        }

        try {
            $path = $file->getRealPath();

            if (! $path || ! is_file($path)) {
                throw new \RuntimeException('Could not inspect the uploaded file.');
            }

            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Could not inspect the uploaded file.');
            }

            $bytes = '';
            while (! feof($stream) && strlen($bytes) < self::SNIFF_BYTES) {
                $bytes .= (string) fread($stream, self::SNIFF_BYTES);
            }
            fclose($stream);

            file_put_contents($tmp, $bytes);

            $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp));

            return ['mime' => $mime, 'head' => $bytes];
        } finally {
            @unlink($tmp);
        }
    }

    private static function isForbiddenMime(string $mime): bool
    {
        foreach (self::FORBIDDEN_HTML_MIMES as $forbidden) {
            if ($mime === $forbidden) {
                return true;
            }
        }

        return false;
    }

    /**
     * A .txt/.csv/.md file that actually contains HTML markup (doctype,
     * script/style tags) is a stored-XSS vehicle — reject it.
     */
    private static function rejectMarkupInText(string $head): void
    {
        $lower = strtolower($head);

        if (preg_match('/<script\b/i', $head)
            || preg_match('/<!doctype\s+html/i', $lower)
            || preg_match('/<html[\s>]/i', $lower)
            || preg_match('/<svg[\s>]/i', $lower)
            || preg_match('/<iframe[\s>]/i', $lower)
            || preg_match('/<object[\s>]/i', $lower)
            || preg_match('/<embed[\s>]/i', $lower)
        ) {
            throw new \RuntimeException('The file content is not allowed.');
        }
    }
}
