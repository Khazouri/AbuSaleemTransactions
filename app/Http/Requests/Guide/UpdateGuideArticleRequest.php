<?php

namespace App\Http\Requests\Guide;

use Illuminate\Validation\Rule;

/**
 * Stage 27 — editing an article.
 *
 * Extends the store request so the two rule sets cannot drift; only the
 * uniqueness check differs, since an article keeping its own code is not a
 * collision.
 */
class UpdateGuideArticleRequest extends StoreGuideArticleRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => [
                'required', 'string', 'max:100',
                Rule::unique('guide_articles', 'code')->ignore($this->route('guideArticle')),
            ],
        ]);
    }
}
