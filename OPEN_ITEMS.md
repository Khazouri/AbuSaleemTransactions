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

### The live committee has only its chair seat filled — Stage 102 — opened 2026-09-26
On the real database committee #16 holds one seated member (chair) and three seatless ones, so it
cannot schedule a meeting until all five Art. 10 (أ) seats are assigned (each to the role
`CommitteeMember::SEAT_ROLES` names). Data, not code. **To close:** fill the five seats on the
Meetings screen (R02/R03), or deactivate the old committee and form a new one.

### Nobody is told when a member declines a meeting date — Stage 102 — opened 2026-09-26
A decline leaves the meeting `pending_confirmation`, and the مقرر sees it only on the meeting page
(and the unanswered members see it in «مهامي»). There is no "date declined" or "meeting approved"
notification. Left out as not asked for. **To close:** add a notification event fired from
`MeetingController::respond()` to the rapporteur on a decline and to all attendees on approval.

### R04's role name still reads «عضو اللجنة» — Stage 102 — opened 2026-09-26
R04 now carries the `ministry_delegate` seat (مندوب الخدمة المدنية بوزارة الحكم المحلي), but its
`RoleSeeder` name and the three `r04.member*` test accounts were left as they were. **To close:**
decide whether to rename R04 or add a dedicated role; renaming touches every test that labels R04.

### Votes do not check that the sitting was convened — Stage 102 — opened 2026-09-26
Convening requires the meeting to be `scheduled` (every member accepted), but votes and decisions
still check only the adopted agenda and the study sequence, not `convened_at`. Pre-existing; not
widened here. **To close:** refuse votes/decisions on a meeting with no `convened_at` and update the
fixtures (`RunsStudySequence`) that vote without convening.

### The decisions register scrolls sideways at 1024px — layout check — opened 2026-09-23
`scripts/check-layout.mjs` fails `/decisions` (register tab) at 1024px in both locales: the table
needs 726px (ar) / 679px (en) in a 670px card. Its 11 columns are already squeezed to ~27px each;
the date column (`nowrap`, 159px in Arabic), the ellipsised subject (165px) and the vote chips
(~100px) set the floor. Every other route × width × locale passes. The 2026-09-22 sweep passed this
page, so it is data-dependent (longer dates or vote tallies on the current database). **To close:**
let the date wrap, cap the subject lower, or drop a column below ~1280px, then rerun the script.

### Every R02 can still see a request returned to intake — filer-only attachments — opened 2026-09-21
`submit` is creator-gated now, so a returned request no longer reaches every R01. But R02 keeps a
`cancel` row at `receive_from_municipality`, and `RequestVisibility`'s assignment clause derives
visibility from outbound rows, so every R02 still sees every returned file there. Pre-existing and
arguably intended (R02 is قسم شؤون الموظفين), left unchanged. **To close:** decide whether cancelling
at intake should be the filer's (and R08's) alone; if so, re-gate that cancel row.

### A manager whose only role is R09, R10 or R11 cannot attach at `receive_and_register` — Stage 101 — opened 2026-09-21
**Resolved (2026-09-21, filer-only attachments) by decision:** only the request's filer attaches documents
now (plus the execution-proof and HR service-file exceptions), so no manager attaches at any stage. The
manager contributes a note and, at `direct_manager_review`, a return with a reason.
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
