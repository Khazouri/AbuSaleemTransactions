<?php

namespace App\Services;

use App\Models\MeetingRequest;
use App\Models\Template;

/**
 * Stage 42 — merges an agenda item's own request/employee/date data into a
 * decision template's subject/body, so picking a template drafts real text
 * instead of a static one the user has to hand-edit around placeholders.
 *
 * Source: [C] §7 ("توليد مسودة القرار من بيانات المعاملة").
 */
class DecisionDraftComposer
{
    private const NONE_AR = '—';

    private const NONE_EN = '—';

    /**
     * Falls back to the other language when a bilingual field is blank — the
     * same preferred/fallback rule DecisionController::localName() already
     * applies when rendering export labels.
     *
     * @return array{subject: string, body: string}
     */
    public function compose(Template $template, MeetingRequest $agendaItem, string $locale): array
    {
        $tokens = $this->tokens($agendaItem, $locale);

        return [
            'subject' => strtr($this->field($template, 'subject', $locale), $tokens),
            'body' => strtr($this->field($template, 'body', $locale), $tokens),
        ];
    }

    /** @return array<string, string> */
    private function tokens(MeetingRequest $agendaItem, string $locale): array
    {
        $requestRecord = $agendaItem->request;
        $meeting = $agendaItem->meeting;
        $none = $locale === 'ar' ? self::NONE_AR : self::NONE_EN;

        return [
            '{{reference_number}}' => $requestRecord?->reference_number ?? $none,
            '{{request_title}}' => $requestRecord?->title ?? $none,
            '{{employee_name}}' => $requestRecord?->createdBy?->name ?? $none,
            '{{department}}' => $this->localName($requestRecord?->department, $locale, $none),
            '{{request_type}}' => $this->localName($requestRecord?->requestType, $locale, $none),
            '{{committee_name}}' => $this->localName($meeting?->committee, $locale, $none),
            '{{meeting_date}}' => $meeting?->scheduled_at?->format('Y-m-d') ?? $none,
            '{{decision_date}}' => now()->format('Y-m-d'),
        ];
    }

    private function field(Template $template, string $prefix, string $locale): string
    {
        $preferred = $locale === 'ar' ? "{$prefix}_ar" : "{$prefix}_en";
        $fallback = $locale === 'ar' ? "{$prefix}_en" : "{$prefix}_ar";

        return $template->{$preferred} ?: ($template->{$fallback} ?: '');
    }

    private function localName(mixed $model, string $locale, string $none): string
    {
        if ($model === null) {
            return $none;
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return $preferred ?: ($fallback ?: $none);
    }
}
