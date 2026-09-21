# Open items

Points left unfinished, unclear, or waiting on a decision. One entry per item, newest
on top. When an item is resolved, mark it **Resolved (date, stage/commit)** in place
rather than deleting it, so the reasoning stays readable.

Format:

```
### <short title> — <source stage> — opened YYYY-MM-DD
What is open, why it was left, and what would close it.
```

---

### A manager whose only role is R09, R10 or R11 cannot attach at `receive_and_register` — Stage 101 — opened 2026-09-21
Appendix 6 row 3 makes الرئيس المباشر «مشارك» in تجهيز الملف الوظيفي. Stage 98 lets the subject's
manager *see* the file there, and Stage 101 pins that they can add a note — but writing rides
`notes_attachments,add`, held by R01–R07 and R12. الرئيس المباشر is a `users.manager_id` relationship,
not a role, so a manager holding only R09/R10/R11 (all retained logins with no duty) is visible-but-
read-only. **To close:** either accept it (no such manager exists in the seeded data), or let the
note/attachment routes admit the subject's manager without the screen grant — a change to the
permission middleware, larger than one cell justifies.

### The Art. 101 notice copy is in-app only — Stage 101 — opened 2026-09-21
`request_notice_copy` defaults to in-app with email off, like `stage_changed`: it is commentary for
the manager and HR, not a deadline. If either party needs it by email, that is one line in
`NotificationSetting::EVENT_TYPES` (or each user's own preference) — deliberately not decided here.

---

### "Was the employee notified?" is still a hand-ticked answer — Stage 100 — opened 2026-09-21

The `employee_notified` question on the execution card (النموذج 17) and the closure audit
(Appendix 47 #4) is answered by hand. Stage 100 considered deriving it from Art. 101's
notice log and left it: the closure notice fires only *after* closure, and deciding which
earlier notices count as "notified of the result" is a rule someone has to choose, not a
lookup. **To close:** decide the rule (e.g. "a notice with a result moment 5–10 exists"),
then derive the answer the way `execution_document_attached` is derived.

### Approvers cannot open the meeting outputs screen — Stage 100 — opened 2026-09-21

Appendix 6 row 13 gives جهة الاعتماد «إشراف حسب الاختصاص» over execution. Stage 100
implemented it on the request page (approvers keep sight of the files they approved, and
R06/R07 can add notes). `meeting_outputs,view` was **not** widened to R05–R07: that screen
is picked by meeting, and they hold no committee seat, so they would open it onto an empty
picker. **To close:** decide whether approvers need a request-based (not meeting-based)
execution list; if so, add it rather than widening the meeting screen.

### Stage 101 — consultation and information layer — Track N — opened 2026-09-21 — **Resolved (2026-09-21, Stage 101)**

The last Track N stage is unbuilt: the remaining مشارك / مطلع cells of Appendix 6 (HR
consulted on rows 2, 4, 6, 7, 9, 14; الرئيس المباشر informed on rows 1 and 14; اللجنة
informed on row 6). See STAGE_PLAN.md Stage 101.

**Resolved.** Built as planned; seven of the thirteen cells were already true and are now pinned by
`ConsultationLayerTest`. See AGENT_NOTES.md. The two residues are recorded as their own items below.
