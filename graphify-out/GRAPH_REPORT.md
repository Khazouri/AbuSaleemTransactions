# Graph Report - AbuSaleemTransactions  (2026-09-20)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 5306 nodes · 13847 edges · 344 communities (98 shown, 246 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 284 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `3fd93ba6`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33
- Community 34
- Community 35
- Community 36
- Community 37
- Community 38
- Community 39
- Community 40
- Community 41
- Community 42
- Community 43
- Community 44
- Community 45
- Community 46
- Community 47
- Community 48
- Community 49
- Community 50
- Community 51
- Community 52
- Community 53
- Community 54
- Community 55
- Community 56
- Community 57
- Community 58
- Community 59
- Community 60
- Community 61
- Community 62
- Community 63
- Community 64
- Community 65
- Community 66
- Community 67
- Community 68
- Community 69
- Community 70
- Community 71
- Community 72
- Community 73
- Community 74
- Community 75
- Community 76
- Community 77
- Community 78
- Community 79
- Community 80
- Community 81
- Community 82
- Community 83
- Community 84
- Community 85
- Community 86
- Community 87
- Community 88
- Community 89
- Community 90
- Community 91
- Community 92
- Community 93
- Community 94
- Community 95
- Community 96
- Community 97
- Community 98
- Community 99
- Community 100
- Community 101
- Community 102
- Community 103
- Community 104
- Community 105
- Community 106
- Community 107
- Community 108
- Community 109
- Community 110
- Community 111
- Community 112
- Community 113
- Community 114
- Community 115
- Community 116
- Community 117
- Community 118
- Community 119
- Community 120
- Community 121
- Community 122
- Community 123
- Community 124
- Community 125
- Community 126
- Community 127
- Community 128
- Community 129
- Community 130
- Community 131
- Community 132
- Community 133
- Community 134
- Community 135
- Community 136
- Community 137
- Community 138
- Community 139
- Community 140
- Community 141
- Community 142
- Community 143
- Community 144
- Community 145
- Community 146
- Community 147
- Community 148
- Community 149
- Community 150
- Community 151
- Community 152
- Community 153
- Community 154
- Community 155
- Community 156
- Community 157
- Community 158
- Community 159
- Community 160
- Community 161
- Community 162
- Community 163
- Community 164
- Community 165
- Community 166
- Community 167
- Community 168
- Community 169
- Community 170
- Community 171
- Community 172
- Community 173
- Community 174
- Community 175
- Community 176
- Community 177
- Community 178
- Community 179
- Community 180
- Community 181
- Community 182
- Community 183
- Community 184
- Community 185
- Community 186
- Community 187
- Community 188
- Community 189
- Community 190
- Community 191
- Community 192
- Community 193
- Community 194
- Community 195
- Community 196
- Community 197
- Community 198
- Community 199
- Community 200
- Community 201
- Community 202
- Community 203
- Community 204
- Community 205
- Community 206
- Community 207
- Community 208
- Community 209
- Community 210
- Community 211
- Community 212
- Community 213
- Community 214
- Community 215
- Community 216
- Community 217
- Community 218
- Community 219
- Community 220
- Community 221
- Community 222
- Community 223
- Community 224
- Community 225
- Community 226
- Community 227
- Community 228
- Community 229
- Community 230
- Community 231
- Community 232
- Community 233
- Community 234
- Community 235
- Community 236
- Community 237
- Community 238
- Community 239
- Community 240
- Community 241
- Community 242
- Community 243
- Community 244
- Community 245
- Community 246
- Community 247
- Community 327
- Community 328
- Community 329
- Community 330
- Community 331
- Community 332

## God Nodes (most connected - your core abstractions)
1. `User` - 450 edges
2. `Request` - 422 edges
3. `Department` - 233 edges
4. `WorkflowStage` - 230 edges
5. `RequestStatus` - 221 edges
6. `RequestType` - 213 edges
7. `Meeting` - 197 edges
8. `Role` - 196 edges
9. `TestCase` - 172 edges
10. `Committee` - 139 edges

## Surprising Connections (you probably didn't know these)
- `MeetingVisibilityTest` --references--> `MeetingVisibility`  [EXTRACTED]
  tests/Feature/MeetingVisibilityTest.php → app/Services/MeetingVisibility.php
- `download()` --calls--> `downloadExport()`  [EXTRACTED]
  frontend/src/views/BackupView.vue → frontend/src/lib/download.js
- `useNotificationsStore` --indirect_call--> `markAllRead()`  [INFERRED]
  frontend/src/stores/notifications.js → frontend/src/views/NotificationsView.vue
- `ApprovalReturnTest` --mixes_in--> `ClosesRequests`  [EXTRACTED]
  tests/Feature/ApprovalReturnTest.php → tests/ClosesRequests.php
- `MeetingOutputsTest` --mixes_in--> `ClosesRequests`  [EXTRACTED]
  tests/Feature/MeetingOutputsTest.php → tests/ClosesRequests.php

## Import Cycles
- None detected.

## Communities (344 total, 246 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.10
Nodes (19): AppealStatus, Department, RequestStatus, RequestType, Role, WorkflowStage, DatabaseSeeder, WorkflowStageSeeder (+11 more)

### Community 1 - "Community 1"
Cohesion: 0.06
Nodes (7): PendingTaskCollector, AgendaOrderingTest, CommitteeCandidatesDashboardTest, DocumentIntegrityTest, MeetingReadinessTest, MyTasksTest, StudySequenceTest

### Community 2 - "Community 2"
Cohesion: 0.04
Nodes (11): Decision, Request, RequestStatusHistory, ActionRequiredNotification, DecisionRecordedNotification, RequestDelayEscalationNotification, AppealEligibility, WithdrawalService (+3 more)

### Community 3 - "Community 3"
Cohesion: 0.06
Nodes (15): MeetingController, MeetingMinutesController, MeetingOutputsController, MeetingReadinessController, MeetingMinutesResource, MeetingOutputsResource, MeetingResource, CommitteeMember (+7 more)

### Community 4 - "Community 4"
Cohesion: 0.05
Nodes (22): ScreenRolePermissionController, ApprovalReferralResource, ApprovalResource, ApprovalReturnResource, AttachmentResource, ConflictOfInterestDeclarationResource, MeetingAttendeeResource, MeetingDiscussionNoteResource (+14 more)

### Community 5 - "Community 5"
Cohesion: 0.03
Nodes (61): acting, actionError, activeAction, ADMINISTRATIVE_ROUTE_ACTIONS, attachmentPreviewError, attachmentPreviewing, attachmentPreviewUrl, auth (+53 more)

### Community 6 - "Community 6"
Cohesion: 0.03
Nodes (17): CloseAppealRequest, StoreAppealAttachmentRequest, StoreAppealRequest, VerifyAppealRequest, StoreApprovalRequest, LoginRequest, StoreConflictOfInterestRequest, ResolveDocumentConflictRequest (+9 more)

### Community 7 - "Community 7"
Cohesion: 0.03
Nodes (7): Approval, MeetingDiscussionNote, PresentationMemo, RequestSpecialCase, RequestStageLog, RequestSuspension, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 8 - "Community 8"
Cohesion: 0.03
Nodes (56): APPEAL_DECISION_OUTCOME_CODES, appealReasons, closureError, closureForm, closureSubmitting, closureTarget, COMPETENT_BODY_OPTIONS, createError (+48 more)

### Community 9 - "Community 9"
Cohesion: 0.04
Nodes (53): reset(), body, error, loading, notes, posting, props, { t, locale } (+45 more)

### Community 10 - "Community 10"
Cohesion: 0.03
Nodes (53): canSubmit, checks, describe(), emit, error, open, props, saving (+45 more)

### Community 11 - "Community 11"
Cohesion: 0.05
Nodes (13): AuditLogController, PerformanceController, RegisterController, ReportController, CheckScreenPermission, ExportPeriodicReportRequest, IndexPerformanceRequest, AuditLogResource (+5 more)

### Community 12 - "Community 12"
Cohesion: 0.06
Nodes (17): ConflictOfInterestController, DashboardController, DepartmentController, DevTestUserController, MeetingDiscussionNoteController, MyTaskController, RequestTrackingController, RequestTypeController (+9 more)

### Community 13 - "Community 13"
Cohesion: 0.04
Nodes (53): acceptedExtensions, auth, blankForm(), canDraft, checkDuplicates(), chooseFiles(), compactPayload(), coveredDocumentKeys (+45 more)

### Community 14 - "Community 14"
Cohesion: 0.08
Nodes (12): RequestController, RecordApprovalReferralRequest, RecordJurisdictionTestRequest, TransitionRequest, UpdateFinancialImpactRequest, RequestDetailResource, AppealOutcomeExecutor, ApprovalReferralService (+4 more)

### Community 15 - "Community 15"
Cohesion: 0.07
Nodes (12): AppealController, AuthController, RequestLifecycleController, UserController, AppealResource, static, UserResource, CorrectionRules (+4 more)

### Community 16 - "Community 16"
Cohesion: 0.07
Nodes (12): ApprovalController, CommitteeCandidateController, MeetingsDashboardController, RequestLegalReviewController, CommitteeCandidateActionRequest, RequestLegalReviewResource, RequestResource, CommitteeStatusService (+4 more)

### Community 17 - "Community 17"
Cohesion: 0.04
Nodes (12): ExecuteAppealOutcomeRequest, RecordAppealJurisdictionTestRequest, UpdateDepartmentRequest, StoreGuideArticleRequest, UpdateGuideArticleRequest, StoreSpecialCaseRequest, UpdateNotificationSettingsRequest, IndexRequest (+4 more)

### Community 18 - "Community 18"
Cohesion: 0.04
Nodes (39): activeTab, allResolved, auth, canRunItems, clockNow, closeError, closing, context (+31 more)

### Community 19 - "Community 19"
Cohesion: 0.04
Nodes (47): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+39 more)

### Community 20 - "Community 20"
Cohesion: 0.07
Nodes (11): User, AdminUserSeeder, TestUserSeeder, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\SoftDeletes, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Illuminate\Support\Facades\Cache (+3 more)

### Community 21 - "Community 21"
Cohesion: 0.08
Nodes (7): DecisionController, StoreDecisionRequest, DecisionResource, VoteResource, Vote, ArtifactNumberGenerator, DecisionEligibility

### Community 22 - "Community 22"
Cohesion: 0.07
Nodes (40): RFC-5987, decodeErrorBody(), downloadExport(), filenameFrom(), formatIndicatorValue(), activeFilters(), applyFilters(), blankFilters() (+32 more)

### Community 23 - "Community 23"
Cohesion: 0.10
Nodes (5): RequestDraftController, RequestDraft, RequestDraftAttachment, IntakeFidelityTest, RequestDraftTest

### Community 24 - "Community 24"
Cohesion: 0.07
Nodes (10): AppealAttachment, MeetingMinuteSignature, Note, RequestLegalReview, AuditObserver, ReportCacheObserver, Illuminate\Database\Eloquent\Model, Illuminate\Support\Arr (+2 more)

### Community 25 - "Community 25"
Cohesion: 0.09
Nodes (6): Committee, CommitteeMeetingTest, CommitteeSeatRosterTest, GatedScreenSideEffectsTest, MeetingMinutesTest, MeetingSchedulingWizardTest

### Community 26 - "Community 26"
Cohesion: 0.05
Nodes (37): actionError, addError, adding, agendaAppealIds, agendaRequestIds, appealOptions, auth, canEditAgenda (+29 more)

### Community 27 - "Community 27"
Cohesion: 0.06
Nodes (37): auth, castVote(), conflictBusy, conflictError, conflictReason, decidingBusy, decisionComment, decisionError (+29 more)

### Community 28 - "Community 28"
Cohesion: 0.09
Nodes (5): Attachment, MeetingAgendaItemContextTest, PresentationMemoTest, RequestIntakeTest, RequestWorkspaceVisibilityTest

### Community 30 - "Community 30"
Cohesion: 0.06
Nodes (33): addMember(), blankCard(), blankCommitteeForm(), cancelCommitteeForm(), CARD_FIELDS, committeeErrors, committeeForm, committeeFormError (+25 more)

### Community 31 - "Community 31"
Cohesion: 0.08
Nodes (9): Screen, ScreenRolePermission, MaintenanceBootstrapper, ScreenRolePermissionSeeder, ScreenSeeder, Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Schedule (+1 more)

### Community 32 - "Community 32"
Cohesion: 0.06
Nodes (29): auth, bellLoading, bellOpen, bellRef, currentTitle, initials, menuOpen, menuRef (+21 more)

### Community 33 - "Community 33"
Cohesion: 0.07
Nodes (36): acceptedExtensions, choose(), classifiesDocument, clearPreview(), docLabel(), documentKey, documentOptionGroups, documentOptions (+28 more)

### Community 34 - "Community 34"
Cohesion: 0.06
Nodes (24): icons, markup, props, collapsedGroups, entries, ICON_BY_CODE, label(), labelled() (+16 more)

### Community 35 - "Community 35"
Cohesion: 0.15
Nodes (4): Setting, AppealVerificationService, SettingSeeder, AppealTest

### Community 36 - "Community 36"
Cohesion: 0.06
Nodes (25): actionError, addAttendee(), addToAgenda(), agendaError, agendaRequestIds, agendaResults, agendaSearch, agendaSearching (+17 more)

### Community 37 - "Community 37"
Cohesion: 0.09
Nodes (8): CommitteeController, CheckMeetingMembership, CommitteeMemberResource, CommitteeResource, MeetingVisibility, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware

### Community 38 - "Community 38"
Cohesion: 0.07
Nodes (28): activeFilters(), applyFilters(), auth, blankFilters(), castVote(), clearFilters(), exportAs(), exportError (+20 more)

### Community 39 - "Community 39"
Cohesion: 0.07
Nodes (26): canRunDestructive, clearHistory(), confirmRun(), confirmTarget, confirmText, enabled, env, execute() (+18 more)

### Community 42 - "Community 42"
Cohesion: 0.06
Nodes (23): activeCommittees, availableExtraInvitees, committeeMembers, detailErrors, details, emit, extraInviteeId, extraInviteeIds (+15 more)

### Community 43 - "Community 43"
Cohesion: 0.10
Nodes (7): NotificationController, PresentationMemoController, NotificationResource, PresentationMemoResource, AppealFileCompiler, PresentationMemoCompiler, Illuminate\Notifications\DatabaseNotification

### Community 44 - "Community 44"
Cohesion: 0.09
Nodes (29): availableOutcomes, caseCanHalt, conflict, correction, data, describe(), determination, error (+21 more)

### Community 45 - "Community 45"
Cohesion: 0.09
Nodes (5): ExportAuditLogRequest, IndexAuditLogRequest, AuditLog, Illuminate\Database\Eloquent\Relations\MorphTo, AuditLogTest

### Community 47 - "Community 47"
Cohesion: 0.07
Nodes (20): { t, locale }, audit, canSubmit, emit, error, form, open, props (+12 more)

### Community 48 - "Community 48"
Cohesion: 0.08
Nodes (24): fileSectionLabel(), fileSectionName(), applySearch(), date(), expectedLine(), fileSectionName(), load(), loadError (+16 more)

### Community 50 - "Community 50"
Cohesion: 0.08
Nodes (21): activeName, blankForm(), cancelForm(), editingId, errors, expandedId, form, formError (+13 more)

### Community 51 - "Community 51"
Cohesion: 0.08
Nodes (4): RequestCorrection, RequestWithdrawal, Illuminate\Database\Eloquent\Relations\HasOne, Illuminate\Support\Carbon

### Community 52 - "Community 52"
Cohesion: 0.09
Nodes (23): articles, blankForm(), body(), cancelForm(), creating, editingId, errors, filtered (+15 more)

### Community 53 - "Community 53"
Cohesion: 0.09
Nodes (23): auth, blankForm(), cancelForm(), departmentOptions, departments, deptLabel(), editingId, errors (+15 more)

### Community 54 - "Community 54"
Cohesion: 0.07
Nodes (22): devDependencies, autoprefixer, axios, concurrently, laravel-vite-plugin, postcss, tailwindcss, vite (+14 more)

### Community 55 - "Community 55"
Cohesion: 0.09
Nodes (18): applyFilters(), blankFilters(), clearFilters(), exportAs(), exportError, exporting, filters, isBusy (+10 more)

### Community 57 - "Community 57"
Cohesion: 0.18
Nodes (4): DelayEscalationTest, DateTimeInterface, DateTimeInterface, StageTimelinessTest

### Community 58 - "Community 58"
Cohesion: 0.12
Nodes (7): DatabaseDumper, Backup, BackupService, MysqlDumper, RuntimeException, Symfony\Component\Process\Exception\ProcessFailedException, ZipArchive

### Community 59 - "Community 59"
Cohesion: 0.12
Nodes (4): StoreRequest, SaveRequestDraftRequest, DuplicatePolicy, Illuminate\Contracts\Validation\Validator

### Community 60 - "Community 60"
Cohesion: 0.08
Nodes (4): ApprovalReferral, ApprovalReturn, AppServiceProvider, Illuminate\Support\ServiceProvider

### Community 61 - "Community 61"
Cohesion: 0.09
Nodes (19): acting, ACTION_ENDPOINTS, closePrompt(), departmentFilter, departments, load(), loadError, loading (+11 more)

### Community 62 - "Community 62"
Cohesion: 0.11
Nodes (22): ACTIONS, activeRoleId, auth, blankCell(), cell(), cellKey(), columnFullyChecked(), dirty (+14 more)

### Community 63 - "Community 63"
Cohesion: 0.09
Nodes (16): channels, eventTypes, isEmpty, load(), loadError, markAllRead(), phone, router (+8 more)

### Community 64 - "Community 64"
Cohesion: 0.11
Nodes (21): active, activeCode, activeFilters(), applyFilters(), blankFilters(), clearFilters(), columns, exportAs() (+13 more)

### Community 65 - "Community 65"
Cohesion: 0.14
Nodes (10): ReportDocument, XlsxWriter, PhpOffice\PhpSpreadsheet\Cell\Coordinate, PhpOffice\PhpSpreadsheet\Cell\DataType, PhpOffice\PhpSpreadsheet\Spreadsheet, PhpOffice\PhpSpreadsheet\Style\Alignment, PhpOffice\PhpSpreadsheet\Style\Border, PhpOffice\PhpSpreadsheet\Style\Fill (+2 more)

### Community 66 - "Community 66"
Cohesion: 0.09
Nodes (22): dependencies, axios, @fontsource/cairo, pinia, vue, vue-i18n, vue-router, devDependencies (+14 more)

### Community 67 - "Community 67"
Cohesion: 0.11
Nodes (16): stageProgressLabel(), applyFilters(), blankFilters(), clearFilters(), filters, isBusy, load(), loadError (+8 more)

### Community 68 - "Community 68"
Cohesion: 0.10
Nodes (19): blankForm(), CENTRAL_ANSWERS, closeReview(), detail, detailError, detailLoading, form, load() (+11 more)

### Community 69 - "Community 69"
Cohesion: 0.09
Nodes (16): conveneError, conveneReason, convening, donutStyle, error, loading, meeting, meetingId (+8 more)

### Community 70 - "Community 70"
Cohesion: 0.13
Nodes (6): AppealAttachmentController, BackupController, AppealAttachmentResource, BackupResource, Symfony\Component\HttpFoundation\StreamedResponse, Throwable

### Community 72 - "Community 72"
Cohesion: 0.11
Nodes (19): blankForm(), cancelForm(), departments, editingId, errors, form, formError, load() (+11 more)

### Community 73 - "Community 73"
Cohesion: 0.11
Nodes (19): activeName, blankForm(), cancelForm(), decisionPlaceholders, editingId, errors, form, formError (+11 more)

### Community 77 - "Community 77"
Cohesion: 0.17
Nodes (4): MaintenanceController, MaintenanceRunResource, static, Illuminate\Http\Response

### Community 80 - "Community 80"
Cohesion: 0.12
Nodes (18): canSubmit, checklist, emit, error, evidence, evidenceEntries, form, open (+10 more)

### Community 81 - "Community 81"
Cohesion: 0.13
Nodes (6): SmsSender, NotificationSetting, SmsChannel, LogSmsSender, Illuminate\Notifications\Notification, Illuminate\Support\Facades\Log

### Community 86 - "Community 86"
Cohesion: 0.15
Nodes (17): canSubmit, describe(), emit, error, form, open, props, reasonOptions (+9 more)

### Community 89 - "Community 89"
Cohesion: 0.15
Nodes (6): AppealStatusSeeder, DepartmentSeeder, RequestStatusSeeder, RoleSeeder, TemplateSeeder, Illuminate\Database\Seeder

### Community 90 - "Community 90"
Cohesion: 0.12
Nodes (15): answers, canSubmit, coveredDocuments, describe(), documents, emit, error, factsVerified (+7 more)

### Community 91 - "Community 91"
Cohesion: 0.13
Nodes (12): bucketScopeClass(), timelinessClass(), board, firingWarnings, kpis, loadError, loading, nextMeeting (+4 more)

### Community 92 - "Community 92"
Cohesion: 0.12
Nodes (15): actionError, actionMessage, busy, createBackup(), creating, download(), includeFiles, isBusy (+7 more)

### Community 93 - "Community 93"
Cohesion: 0.14
Nodes (16): blankForm(), cancelForm(), editingId, errors, form, formError, load(), loadError (+8 more)

### Community 102 - "Community 102"
Cohesion: 0.15
Nodes (15): answering, canSubmit, canSubmitResult, describe(), emit, error, form, open (+7 more)

### Community 108 - "Community 108"
Cohesion: 0.20
Nodes (5): EscalateDelayedRequests, FlagOverdueRequests, RunBackup, RequestDeadlineService, Illuminate\Console\Command

### Community 116 - "Community 116"
Cohesion: 0.21
Nodes (3): MeetingOutputTransitionException, self, DomainException

### Community 117 - "Community 117"
Cohesion: 0.20
Nodes (4): AttachmentController, NoteController, NoteResource, RequestVisibility

### Community 118 - "Community 118"
Cohesion: 0.14
Nodes (3): StoreMeetingAgendaRequest, UpdateMeetingAgendaItemRequest, UrgencyRules

### Community 121 - "Community 121"
Cohesion: 0.18
Nodes (8): PdfWriter, Illuminate\Support\Facades\File, League\CommonMark\Environment\Environment, League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension, League\CommonMark\Extension\GithubFlavoredMarkdownExtension, League\CommonMark\MarkdownConverter, Mpdf\Mpdf, e()

### Community 125 - "Community 125"
Cohesion: 0.15
Nodes (3): ReopenAppealRequest, ReopenRequest, ReopenReasonCatalog

### Community 126 - "Community 126"
Cohesion: 0.18
Nodes (13): addAdminItem(), addAppealItem(), addRequestItem(), applyRuleOrder(), loadAppealOptions(), loadMeeting(), loadOrdering(), loadStats() (+5 more)

### Community 139 - "Community 139"
Cohesion: 0.29
Nodes (3): MaintenanceCommandException, self, Symfony\Component\Process\Process

### Community 140 - "Community 140"
Cohesion: 0.24
Nodes (3): GuideArticleController, GuideArticleResource, GuideArticle

### Community 141 - "Community 141"
Cohesion: 0.22
Nodes (3): Permission, PermissionSeeder, Illuminate\Database\Eloquent\Relations\BelongsToMany

### Community 142 - "Community 142"
Cohesion: 0.33
Nodes (4): SystemNotification, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Notifications\Messages\MailMessage

### Community 151 - "Community 151"
Cohesion: 0.18
Nodes (10): CONFLICT_KINDS, HALTING_CASES, MATERIAL_ERROR_KINDS, PRIOR_RELATIONS, SPECIAL_CASES, SUBSTANTIVE_ERROR_KINDS, URGENCY_REASONS, VALIDITY_ANSWERS (+2 more)

### Community 152 - "Community 152"
Cohesion: 0.29
Nodes (11): createAppeal(), extractErrorMessage(), load(), resetCreateForm(), submitClosure(), submitExecution(), submitJurisdictionTest(), submitLegalReview() (+3 more)

### Community 169 - "Community 169"
Cohesion: 0.24
Nodes (4): static, UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Support\Str

### Community 170 - "Community 170"
Cohesion: 0.20
Nodes (9): needsFactsAndBasis, needsRefusal, DECISION_INSTRUMENTS, DEFERRAL_FIELDS, isSubstantiveOutcome(), needsRefusalReason(), REASONED_OUTCOMES, REFUSAL_REASON_CODES (+1 more)

### Community 187 - "Community 187"
Cohesion: 0.25
Nodes (6): AGENDA_RANKS, agendaRankLabel(), PRIORITY_GROUNDS, PRIORITY_LEVELS, priorityGroundLabel(), STUDY_STEPS

### Community 241 - "Community 241"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 242 - "Community 242"
Cohesion: 0.40
Nodes (4): Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession, Laravel\Sanctum\Sanctum

### Community 243 - "Community 243"
Cohesion: 0.40
Nodes (4): AUDIT_CHECKS, CLOSER_CHECKS, CLOSURE_FIELDS, DERIVED_CHECKS

### Community 245 - "Community 245"
Cohesion: 0.50
Nodes (4): generateMemo(), loadMemo(), saveMemo(), syncMemoDraft()

### Community 246 - "Community 246"
Cohesion: 0.50
Nodes (4): load(), postNote(), setItemState(), toggleStep()

## Knowledge Gaps
- **852 isolated node(s):** `canSubmit`, `checks`, `error`, `open`, `props` (+847 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1867 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **246 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Community 20` to `Community 0`, `Community 1`, `Community 2`, `Community 3`, `Community 4`, `Community 7`, `Community 11`, `Community 12`, `Community 14`, `Community 15`, `Community 16`, `Community 21`, `Community 23`, `Community 25`, `Community 28`, `Community 29`, `Community 31`, `Community 35`, `Community 37`, `Community 40`, `Community 41`, `Community 43`, `Community 45`, `Community 46`, `Community 49`, `Community 51`, `Community 56`, `Community 57`, `Community 58`, `Community 59`, `Community 65`, `Community 70`, `Community 71`, `Community 74`, `Community 75`, `Community 76`, `Community 78`, `Community 81`, `Community 82`, `Community 87`, `Community 88`, `Community 94`, `Community 95`, `Community 96`, `Community 97`, `Community 99`, `Community 100`, `Community 101`, `Community 103`, `Community 104`, `Community 105`, `Community 106`, `Community 107`, `Community 111`, `Community 112`, `Community 113`, `Community 114`, `Community 115`, `Community 116`, `Community 117`, `Community 119`, `Community 122`, `Community 123`, `Community 127`, `Community 128`, `Community 133`, `Community 136`, `Community 137`, `Community 138`, `Community 139`, `Community 144`, `Community 145`, `Community 146`, `Community 147`, `Community 148`, `Community 149`, `Community 150`, `Community 153`, `Community 154`, `Community 155`, `Community 156`, `Community 157`, `Community 158`, `Community 159`, `Community 160`, `Community 161`, `Community 162`, `Community 163`, `Community 164`, `Community 165`, `Community 166`, `Community 167`, `Community 168`, `Community 169`, `Community 171`, `Community 172`, `Community 173`, `Community 174`, `Community 175`, `Community 178`, `Community 179`, `Community 180`, `Community 181`, `Community 182`, `Community 183`, `Community 184`, `Community 188`, `Community 189`, `Community 191`, `Community 194`, `Community 244`?**
  _High betweenness centrality (0.118) - this node is a cross-community bridge._
- **Why does `Request` connect `Community 2` to `Community 0`, `Community 1`, `Community 3`, `Community 4`, `Community 7`, `Community 11`, `Community 12`, `Community 14`, `Community 15`, `Community 16`, `Community 20`, `Community 24`, `Community 25`, `Community 28`, `Community 29`, `Community 35`, `Community 40`, `Community 41`, `Community 43`, `Community 46`, `Community 49`, `Community 51`, `Community 56`, `Community 57`, `Community 59`, `Community 60`, `Community 65`, `Community 70`, `Community 75`, `Community 78`, `Community 100`, `Community 101`, `Community 104`, `Community 105`, `Community 106`, `Community 107`, `Community 108`, `Community 111`, `Community 113`, `Community 114`, `Community 115`, `Community 116`, `Community 117`, `Community 119`, `Community 122`, `Community 124`, `Community 133`, `Community 134`, `Community 135`, `Community 136`, `Community 137`, `Community 138`, `Community 147`, `Community 148`, `Community 149`, `Community 154`, `Community 155`, `Community 156`, `Community 158`, `Community 159`, `Community 161`, `Community 162`, `Community 164`, `Community 165`, `Community 171`, `Community 172`, `Community 173`, `Community 174`, `Community 179`, `Community 180`, `Community 181`, `Community 182`, `Community 183`, `Community 186`, `Community 188`, `Community 189`, `Community 191`, `Community 192`, `Community 194`, `Community 199`, `Community 201`, `Community 236`, `Community 239`, `Community 240`, `Community 244`?**
  _High betweenness centrality (0.090) - this node is a cross-community bridge._
- **Why does `Meeting` connect `Community 3` to `Community 0`, `Community 1`, `Community 2`, `Community 128`, `Community 4`, `Community 7`, `Community 136`, `Community 138`, `Community 11`, `Community 12`, `Community 16`, `Community 20`, `Community 21`, `Community 24`, `Community 153`, `Community 25`, `Community 155`, `Community 28`, `Community 29`, `Community 35`, `Community 37`, `Community 168`, `Community 40`, `Community 41`, `Community 43`, `Community 173`, `Community 46`, `Community 174`, `Community 49`, `Community 51`, `Community 180`, `Community 181`, `Community 56`, `Community 60`, `Community 70`, `Community 75`, `Community 78`, `Community 87`, `Community 94`, `Community 95`, `Community 96`, `Community 97`, `Community 98`, `Community 99`, `Community 101`, `Community 104`, `Community 105`, `Community 106`, `Community 107`, `Community 237`, `Community 238`, `Community 111`, `Community 113`, `Community 122`, `Community 123`?**
  _High betweenness centrality (0.036) - this node is a cross-community bridge._
- **What connects `canSubmit`, `checks`, `error` to the rest of the system?**
  _852 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.09974193548387096 - nodes in this community are weakly interconnected._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.05901389682086427 - nodes in this community are weakly interconnected._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.03696969696969697 - nodes in this community are weakly interconnected._