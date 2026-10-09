<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a RequestType for its admin screen.
 *
 * Deliberately NOT used by RequestController::filters()/intakeOptions() — those
 * hand-pick the handful of columns their own screens need (and intake in
 * particular is served to every employee), so routing them through this fuller
 * resource would widen what a request-filing screen discloses for no gain.
 */
class RequestTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'default_sla_days' => $this->default_sla_days,
            'decision_grade_threshold' => $this->decision_grade_threshold,
            'is_active' => $this->is_active,
            'default_has_financial_impact' => $this->default_has_financial_impact,
            'default_administrative_route' => $this->default_administrative_route,
            'legal_basis_ar' => $this->legal_basis_ar,
            'legal_basis_note_ar' => $this->legal_basis_note_ar,

            // [D] Appendix 57's matrix. Always an array, never null, so the
            // editor can render "no documents yet" rather than having to
            // distinguish an empty list from an absent column.
            'required_documents' => $this->required_documents ?? [],

            // Counts come from withCount() in the controller; whenCounted()
            // omits them otherwise, so this resource never queries on its own.
            //
            // Both exist to explain a refused delete BEFORE the user attempts
            // one: a type carrying either cannot be removed, only deactivated.
            'requests_count' => $this->whenCounted('requests'),
            'workflow_transitions_count' => $this->whenCounted('workflowTransitions'),
        ];
    }
}
