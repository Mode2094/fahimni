<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentCertificate;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentCertificateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! app(FeatureService::class)->hasFeature('certificates_enabled')) {
            return response()->json(['certificates' => []]);
        }

        $certificates = StudentCertificate::where('user_id', $request->user()->id)
            ->where('status', 'issued')
            ->with(['course'])
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'certificates' => $certificates->map(fn (StudentCertificate $c) => $this->certificatePayload($c)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! app(FeatureService::class)->hasFeature('certificates_enabled')) {
            return response()->json(['message' => 'Certificates are not included in your current plan.'], 403);
        }

        $certificate = StudentCertificate::with(['course'])->findOrFail($id);

        if ($certificate->user_id !== $request->user()->id && ! $request->user()->is_super_admin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'certificate' => $this->certificatePayload($certificate),
        ]);
    }

    public function verify(string $number): JsonResponse
    {
        $certificate = StudentCertificate::with(['course', 'user', 'tenant'])
            ->where('certificate_number', $number)
            ->first();

        if (! $certificate || $certificate->status !== 'issued') {
            return response()->json([
                'valid' => false,
                'message' => 'Certificate not found or revoked.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'certificate' => [
                'certificate_number' => $certificate->certificate_number,
                'student_name' => $certificate->user?->name,
                'course_title' => $certificate->course?->title,
                'issued_at' => $certificate->issued_at?->toIso8601String(),
                'issuer' => $certificate->tenant?->name,
                'template' => $certificate->template,
            ],
        ]);
    }

    private function certificatePayload(StudentCertificate $c): array
    {
        return [
            'id' => $c->id,
            'certificate_number' => $c->certificate_number,
            'course_id' => $c->course_id,
            'course_title' => $c->course?->title,
            'student_name' => $c->metadata['student_name'] ?? null,
            'issued_at' => $c->issued_at?->toIso8601String(),
            'status' => $c->status,
            'template' => $c->template,
            'accent_color' => $c->accent_color,
            'signature_name' => $c->signature_name,
            'signature_title' => $c->signature_title,
        ];
    }
}
