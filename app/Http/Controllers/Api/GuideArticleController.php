<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guide\StoreGuideArticleRequest;
use App\Http\Requests\Guide\UpdateGuideArticleRequest;
use App\Http\Resources\GuideArticleResource;
use App\Models\GuideArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 27 — the user guide's content, read by everyone and written by R08.
 *
 * The read/write split is the `user_guide` screen's seeded grants exactly:
 * `view`/`print` to all roles, `add`/`edit`/`delete` to R08 only. No seeder
 * change was needed to build this — the matrix has described an editable guide
 * since Stage 3; only the content behind it was missing.
 */
class GuideArticleController extends Controller
{
    /**
     * Readers see published articles; editors see drafts too.
     *
     * A draft is an article someone is still writing, so showing it to readers
     * would defeat the flag. The check is on `edit` rather than on a role code
     * so an admin who re-cuts the permission matrix gets the behaviour they
     * configured.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $canEdit = $request->user()->hasScreenPermission('user_guide', 'can_edit');

        $articles = GuideArticle::query()
            ->unless($canEdit, fn ($query) => $query->where('is_active', true))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('title_ar')
            ->get();

        return GuideArticleResource::collection($articles);
    }

    public function store(StoreGuideArticleRequest $request): JsonResponse
    {
        $article = GuideArticle::create($request->validated());

        return (new GuideArticleResource($article))->response()->setStatusCode(201);
    }

    public function update(UpdateGuideArticleRequest $request, GuideArticle $guideArticle): GuideArticleResource
    {
        $guideArticle->update($request->validated());

        return new GuideArticleResource($guideArticle);
    }

    /**
     * A plain delete, unlike the master-data screens: nothing references a help
     * article, so there is no history to preserve by deactivating instead.
     */
    public function destroy(GuideArticle $guideArticle): JsonResponse
    {
        $guideArticle->delete();

        return response()->json(['message' => 'تم حذف المقال.']);
    }
}
