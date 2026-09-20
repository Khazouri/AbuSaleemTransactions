<?php

namespace App\Services;

use App\Models\Request;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Stage 79 — [D] Art. 101's notices a request's own employee actually received.
 *
 * Read back from Laravel's own `notifications` rows rather than from a table of
 * our own: an in-app notification IS the delivery record, and Stage 61's
 * AppealFileCompiler already reads إثبات التبليغ the same way. Email and SMS
 * keep no delivery log anywhere in this system, so this register is honestly
 * the in-app half — the screens that render it say so.
 *
 * Stage 89 lifted this out of RequestController, where it was private, when the
 * employee tracking screen needed the same register: the request workspace's
 * notices card and the tracking panel's must answer "what was I told about this
 * file" identically, and two copies of this query would be free to drift.
 *
 * Deliberately not a method on EmployeeNoticeService, whose own docblock says it
 * "owns the mapping only" — which of Art. 101's twelve moments a status change
 * is. Reading the register back is a fourth concern from the three that class
 * already keeps apart (the mapping, who hears it, how it reads).
 */
class EmployeeNoticeRegister
{
    /**
     * Ordered oldest-first so the card reads as the file's notification
     * history, which is also what Art. 100's timeline wants of it. The moment
     * is carried in the stored payload, so nothing here re-derives it — a
     * notice says what it said when it was sent, even if the mapping later
     * changes.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Request $requestRecord): array
    {
        // Stage 95 — addressed to صاحب العلاقة, matching who
        // NotificationDispatcher::requestNotice() actually sent them to.
        if ($requestRecord->subject_user_id === null) {
            return [];
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $requestRecord->subject_user_id)
            // Both kinds, because this card answers "what was this employee
            // actually told about this file" and leaving one out makes that
            // answer wrong. `reference_assigned` is not one of Art. 101's
            // twelve moments, so it carries a null `moment`/`moment_number`
            // — its payload otherwise mirrors RequestNoticeNotification's
            // shape precisely so this one mapper serves both.
            ->whereIn('data->event_type', ['request_notice', 'reference_assigned'])
            ->where('data->request_id', $requestRecord->getKey())
            ->oldest('created_at')
            ->get()
            ->map(fn (DatabaseNotification $notice): array => [
                'id' => $notice->id,
                // Carried so the screen can label a notice that has no Art.
                // 101 moment number instead of rendering a blank.
                'event_type' => $notice->data['event_type'] ?? null,
                'moment' => $notice->data['moment'] ?? null,
                'moment_number' => $notice->data['moment_number'] ?? null,
                'title_ar' => $notice->data['title_ar'] ?? null,
                'title_en' => $notice->data['title_en'] ?? null,
                'body_ar' => $notice->data['body_ar'] ?? null,
                'body_en' => $notice->data['body_en'] ?? null,
                'sent_at' => $notice->created_at?->toIso8601String(),
                'read_at' => $notice->read_at?->toIso8601String(),
            ])
            ->all();
    }
}
