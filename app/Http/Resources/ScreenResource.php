<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Screen for the SPA's navigation menu.
 *
 * Both names are sent, not just the one matching the current language: the
 * user can flip between AR and EN in the top bar, and re-fetching the whole
 * menu on every toggle would be wasteful. The sidebar simply picks the field
 * that matches the active locale.
 */
class ScreenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Stable key. The sidebar uses it for :key, and Stage 9 will use it
            // to look the screen's permissions up.
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            // Vue router path this entry links to.
            'route' => $this->route,
            'icon' => $this->icon,
            // Stage 28: sidebar cluster slug, null for ungrouped (top-level) screens.
            'group' => $this->group,
            'sort_order' => $this->sort_order,
        ];
    }
}
