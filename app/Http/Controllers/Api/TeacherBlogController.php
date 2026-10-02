<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Tenant;
use App\Plans\QuotaService;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class TeacherBlogController extends Controller
{
    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $this->tenantId($request))
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function payload(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'content' => $post->content,
            'cover_image' => $post->cover_image,
            'category' => $post->category,
            'tags' => $post->tags ?? [],
            'status' => $post->status,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    private function isSafeHttpUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'])) {
            return false;
        }

        return in_array(strtolower((string) $parsed['scheme']), ['http', 'https'], true);
    }

    private function makeSlug(Request $request, string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($title, '-', 'en') ?: 'post-'.Str::random(6);
        $slug = $base;
        $i = 1;
        while (Post::where('tenant_id', $this->tenantId($request))
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $posts = Post::where('tenant_id', $this->tenantId($request))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['posts' => $posts->map(fn ($p) => $this->payload($p))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $features = app(FeatureService::class);
        app(QuotaService::class)->assertCan($request->current_tenant->id, 'blog_posts');

        if (! $features->hasFeature('blog_enabled')) {
            return response()->json(['message' => 'Blog is not included in your current plan.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string|max:100000',
            'cover_image' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:50',
            'status' => 'nullable|string|in:draft,published',
        ]);

        if (! empty($validated['cover_image']) && ! $this->isSafeHttpUrl($validated['cover_image'])) {
            return response()->json(['message' => 'cover_image must be a valid http(s) URL.'], 422);
        }

        $post = Post::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId($request),
            'title' => $validated['title'],
            'slug' => $this->makeSlug($request, $validated['title']),
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $validated['content'],
            'cover_image' => $validated['cover_image'] ?? null,
            'category' => $validated['category'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        return response()->json(['post' => $this->payload($post)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->ensureTeacher($request);

        $post = Post::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'sometimes|required|string|max:100000',
            'cover_image' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:50',
            'status' => 'nullable|string|in:draft,published',
        ]);

        if (isset($validated['cover_image']) && ! empty($validated['cover_image']) && ! $this->isSafeHttpUrl($validated['cover_image'])) {
            return response()->json(['message' => 'cover_image must be a valid http(s) URL.'], 422);
        }

        $data = $validated;
        if (isset($validated['title'])) {
            $data['slug'] = $this->makeSlug($request, $validated['title'], $post->id);
        }
        if (isset($validated['status']) && $validated['status'] === 'published' && $post->status !== 'published') {
            $data['published_at'] = now();
        }

        $post->update($data);

        return response()->json(['post' => $this->payload($post->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->ensureTeacher($request);

        $post = Post::where('tenant_id', $this->tenantId($request))->findOrFail($id);
        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $this->ensureTeacher($request);

        $post = Post::where('tenant_id', $this->tenantId($request))->findOrFail($id);
        $post->update(['status' => 'published', 'published_at' => now()]);

        return response()->json(['post' => $this->payload($post->fresh())]);
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        $this->ensureTeacher($request);

        $post = Post::where('tenant_id', $this->tenantId($request))->findOrFail($id);
        $post->update(['status' => 'draft']);

        return response()->json(['post' => $this->payload($post->fresh())]);
    }

    // ─── Public ───────────────────────────────────
    public function publicPosts(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $posts = Post::where('tenant_id', $tenant->id)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get();

        return response()->json(['posts' => $posts->map(fn ($p) => $this->payload($p))]);
    }

    public function publicPost(string $slug, string $postSlug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $post = Post::where('tenant_id', $tenant->id)
            ->where('slug', $postSlug)
            ->where('status', 'published')
            ->first();

        if (! $post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        return response()->json(['post' => $this->payload($post)]);
    }
}
