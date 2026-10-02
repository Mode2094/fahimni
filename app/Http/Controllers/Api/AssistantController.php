<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    /**
     * اسألني — سؤال المعلم عن المنصة.
     *
     * @bodyParam question string required نص سؤال المعلم (بالعربية أو العامية).
     * @bodyParam page string المسار الحالي للصفحة (للسياق).
     */
    public function ask(Request $request): JsonResponse
    {
        if (! config('assistant.enabled', true)) {
            return response()->json([
                'answer' => 'مساعد "اسألني" معطّل حالياً.',
                'mode' => 'disabled',
                'matched' => null,
            ]);
        }

        $validated = $request->validate([
            'question' => 'required|string|max:2000',
            'page' => 'nullable|string|max:500',
        ]);

        $result = $this->assistant->answer(
            $request,
            $validated['question'],
            (string) ($validated['page'] ?? ''),
        );

        return response()->json($result);
    }
}
