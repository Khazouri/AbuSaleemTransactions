<?php

namespace App\Notifications;

use App\Models\Request;

/**
 * The submitter's notice that their request's number has changed.
 *
 * Intake hands the employee a `PM-RCV` receipt (Art. 15: handing the request
 * to the direct manager is not a قيد). When the receiving body accepts the
 * file, the قيد allocates a `PM-COM` رقم إشاري and every later notice, letter
 * and register entry quotes THAT number instead. Without this notice the
 * employee is holding a slip whose number appears nowhere again.
 *
 * Deliberately its own event rather than extra wording inside Art. 101's
 * moment 1 — which also fires at this hop and keeps [D]'s own formula for
 * receipt into the official track. The two are not redundant: moment 1 says
 * the file entered the track, this one says which number now identifies it.
 * See AGENT_NOTES.md; a later "de-duplicate the notifications" pass must not
 * collapse them.
 *
 * Art. 102 is a rule about CONTENT, so — as in RequestNoticeNotification — it
 * is enforced by what this class can see: two numbers belonging to the
 * reader's own file and nothing else. No tally, no member's opinion, no
 * internal memo, no other employee's record, so it cannot leak one.
 *
 * Its payload deliberately mirrors RequestNoticeNotification's key shape
 * (with a null `moment`) so RequestController::employeeNotices() can list
 * both kinds through one mapper.
 */
class RequestReferenceAssignedNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly ?string $receipt;

    public function __construct(Request $requestRecord)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->reference_number;
        // Null for a row created before the receipt series existed; the body
        // then simply announces the new number without claiming to replace
        // one the employee never held.
        $this->receipt = $requestRecord->intake_receipt_number;
    }

    public function eventType(): string
    {
        return 'reference_assigned';
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        [$bodyAr, $bodyEn] = $this->body();

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'intake_receipt_number' => $this->receipt,
            // No Art. 101 moment: this notice is not one of its twelve.
            'moment' => null,
            'moment_number' => null,
            'title_ar' => 'قيد المعاملة ومنحها رقمها المرجعي',
            'title_en' => 'Request registered and assigned its reference number',
            'body_ar' => $bodyAr,
            'body_en' => $bodyEn,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function body(): array
    {
        if ($this->receipt === null) {
            return [
                "نفيدكم بقيد معاملتكم ومنحها الرقم المرجعي {$this->reference}، ويُذكر هذا الرقم في كل مراجعة أو مراسلة لاحقة بشأنها.",
                "Your request has been registered and assigned reference number {$this->reference}. Please quote this number in any follow-up or correspondence about it.",
            ];
        }

        return [
            "نفيدكم بأن معاملتكم المقدمة بإيصال الاستلام رقم {$this->receipt} قد قُيدت لدى الجهة المستلمة ومُنحت الرقم المرجعي {$this->reference}. ويُذكر الرقم المرجعي، لا رقم الإيصال، في كل مراجعة أو مراسلة لاحقة بشأنها.",
            "Your request, filed under intake receipt {$this->receipt}, has been registered by the receiving body and assigned reference number {$this->reference}. Please quote the reference number — not the receipt number — in any follow-up or correspondence about it.",
        ];
    }
}
