import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AppLayout from '../layouts/AppLayout.vue'

/**
 * Application routes and the navigation guard.
 *
 * Structure: /login stands alone (no shell), while every signed-in screen is a
 * CHILD of the AppLayout route. Nesting means the sidebar and top bar are
 * mounted once and stay put as you navigate — only the inner RouterView
 * changes — so the menu doesn't flicker or refetch between screens.
 *
 * `meta` drives access:
 *   requiresAuth  must be signed in (set once on the parent, inherited by all
 *                 children — vue-router merges meta down the matched chain)
 *   guestOnly     must NOT be signed in
 *   screenCode    which `screens` row this route corresponds to; Stage 9 uses
 *                 it to check the permission matrix before allowing entry
 */

/*
 * There used to be a `placeholderScreens` list here — screens that existed in
 * the menu but whose real UI was still to come, each rendering PlaceholderView.
 * Stages 25-27 built the last three (decisions, backup, user_guide), so the
 * list is gone and PlaceholderView with it: every seeded screen now has a real
 * component below.
 */

/*
 * Two seeded screens are deliberately absent from the sidebar: request_details
 * ("/requests/:id") and notes_attachments. Both need a specific request id, so
 * they can't be linked from a static menu — stores/screens.js filters any
 * screen whose route carries ":id" out of navItems, while they keep real
 * routes below (added in Stage 15).
 */

const routes = [
  {
    path: '/login',
    name: 'login',
    // Lazy-loaded: the login page ships as its own chunk, so a signed-in user
    // never downloads it.
    component: () => import('../views/LoginView.vue'),
    meta: { guestOnly: true },
  },
  {
    // The shell. Everything below it requires authentication.
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue'),
        meta: { screenCode: 'dashboard' },
      },
      {
        // Stage 11 — searchable, paginated request work queue.
        path: 'requests',
        name: 'requests',
        component: () => import('../views/RequestsView.vue'),
        meta: { screenCode: 'requests' },
      },
      {
        // Stage 13 — full request intake and reference allocation.
        path: 'requests/create',
        name: 'request_intake',
        component: () => import('../views/RequestIntakeView.vue'),
        meta: { screenCode: 'request_intake' },
      },
      {
        // Stage 15 — request workspace with workflow actions and timeline.
        path: 'requests/:id',
        name: 'request_details',
        component: () => import('../views/RequestDetailView.vue'),
        meta: { screenCode: 'request_details' },
      },
      {
        // Stage 6 — department tree management.
        path: 'departments',
        name: 'departments',
        component: () => import('../views/DepartmentsView.vue'),
        meta: { screenCode: 'departments' },
      },
      {
        // Stage 7 — user account management.
        path: 'users',
        name: 'users',
        component: () => import('../views/UsersView.vue'),
        meta: { screenCode: 'users' },
      },
      {
        // Stage 8 — roles & permissions matrix editor.
        path: 'roles',
        name: 'roles_permissions',
        component: () => import('../views/RolesPermissionsView.vue'),
        meta: { screenCode: 'roles_permissions' },
      },
      {
        // Stage 10 — key/value application settings.
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue'),
        meta: { screenCode: 'settings' },
      },
      {
        // Stage 10 — reusable bilingual text templates.
        path: 'templates',
        name: 'templates',
        component: () => import('../views/TemplatesView.vue'),
        meta: { screenCode: 'templates' },
      },
      {
        // Stage 20 — committee administration and the meeting schedule.
        path: 'meetings',
        name: 'meetings',
        component: () => import('../views/MeetingsView.vue'),
        meta: { screenCode: 'meetings' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: dashboard.
        path: 'meetings/dashboard',
        name: 'meetings_dashboard',
        component: () => import('../views/MeetingsDashboardView.vue'),
        meta: { screenCode: 'meetings_dashboard' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: candidate requests.
        path: 'meetings/candidates',
        name: 'committee_candidates',
        component: () => import('../views/CommitteeCandidatesView.vue'),
        meta: { screenCode: 'committee_candidates' },
      },
      {
        // Stage 68 — [D] Art. 21 / [E] stage 08: pre-meeting legal review.
        path: 'meetings/legal-review',
        name: 'legal_review',
        component: () => import('../views/LegalReviewView.vue'),
        meta: { screenCode: 'legal_review' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: agenda builder.
        path: 'meetings/agenda',
        name: 'meeting_agenda',
        component: () => import('../views/MeetingAgendaBuilderView.vue'),
        meta: { screenCode: 'meeting_agenda' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: readiness gate.
        path: 'meetings/readiness',
        name: 'meeting_readiness',
        component: () => import('../views/MeetingReadinessView.vue'),
        meta: { screenCode: 'meeting_readiness' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: live runner.
        path: 'meetings/live',
        name: 'meeting_live',
        component: () => import('../views/MeetingLiveView.vue'),
        meta: { screenCode: 'meeting_live' },
      },
      {
        // Stage 20 — single meeting workspace: agenda builder + attendance.
        // Needs a specific meeting id, so (like request_details) it has no
        // separate sidebar entry. vue-router
        // ranks static segments (meetings/agenda etc. above) over this :id
        // route regardless of declaration order, so they aren't shadowed.
        path: 'meetings/:id',
        name: 'meeting_details',
        component: () => import('../views/MeetingDetailView.vue'),
        meta: { screenCode: 'meetings' },
      },
      {
        // Stage 22 — read-only audit trail viewer.
        path: 'audit-log',
        name: 'audit_log',
        component: () => import('../views/AuditLogView.vue'),
        meta: { screenCode: 'audit_log' },
      },
      {
        // Stage 24 — filtered request reporting with Excel/PDF export.
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: { screenCode: 'reports' },
      },
      {
        // Stage 80 — [D] Art. 98's twelve official registers.
        path: 'registers',
        name: 'registers',
        component: () => import('../views/RegistersView.vue'),
        meta: { screenCode: 'registers' },
      },
      {
        // Stage 23 — notification history and channel preferences.
        path: 'notifications',
        name: 'notifications',
        component: () => import('../views/NotificationsView.vue'),
        meta: { screenCode: 'notifications' },
      },
      // Stage 18 — role-specific queues backed by one reusable approval view.
      ...[
        ['reviewer_approval', 'reviewer'],
        ['committee_head_approval', 'committee-head'],
        ['admin_manager_approval', 'admin-manager'],
        ['ministry_approval', 'ministry'],
        // Stage 57 — 'authority_approval' (competent_authority) removed.
        ['final_approval', 'final'],
      ].map(([screenCode, level]) => ({
        path: `approvals/${level}`,
        name: screenCode,
        component: () => import('../views/ApprovalQueueView.vue'),
        meta: { screenCode, approvalLevel: level },
      })),
      {
        // Stage 25 — the decisions register and the pending-votes worklist.
        // Recording a decision stays on the meeting screen, where the agenda
        // context and the signature pad already are.
        path: 'decisions',
        name: 'decisions',
        component: () => import('../views/DecisionsView.vue'),
        meta: { screenCode: 'decisions' },
      },
      {
        // Stage 28 — meetings-unit navigation shell: minutes.
        path: 'meetings/minutes',
        name: 'meeting_minutes',
        component: () => import('../views/MeetingMinutesView.vue'),
        meta: { screenCode: 'meeting_minutes' },
      },
      {
        // Stage 37 — meeting decisions followed through execution and close.
        path: 'meetings/outputs',
        name: 'meeting_outputs',
        component: () => import('../views/MeetingOutputsView.vue'),
        meta: { screenCode: 'meeting_outputs' },
      },
      {
        // Stage 26 — snapshots of the database and its stored files.
        path: 'backup',
        name: 'backup',
        component: () => import('../views/BackupView.vue'),
        meta: { screenCode: 'backup' },
      },
      {
        // The maintenance console — migrations, caches and dependency
        // installs for a host with no shell (cPanel shared hosting).
        path: 'maintenance',
        name: 'maintenance',
        component: () => import('../views/MaintenanceView.vue'),
        meta: { screenCode: 'maintenance' },
      },
      {
        // Stage 27 — help articles, read by everyone and edited by R08.
        path: 'guide',
        name: 'user_guide',
        component: () => import('../views/GuideView.vue'),
        meta: { screenCode: 'user_guide' },
      },
      {
        // Stage 58, Track J — appeals against an already-decided request.
        path: 'appeals',
        name: 'appeals',
        component: () => import('../views/AppealsView.vue'),
        meta: { screenCode: 'appeals' },
      },
    ],
  },

  // Unknown URL: send signed-in users to the dashboard. Should become a proper
  // 404 page once the real screens exist.
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  // Clean URLs with no '#'. Requires the dev server (and any production host)
  // to fall back to index.html for unknown paths.
  history: createWebHistory(),
  routes,
})

/**
 * Global guard — runs before every navigation.
 * Return a location to redirect, or true to allow.
 *
 * Stage 9 — checks `meta.screenCode` against the user's permission matrix
 * (auth.can), so a user can't reach a screen their role can't view just by
 * typing/bookmarking the URL. This is UX only: the matching
 * screen.permission middleware on the API is what actually enforces it, so
 * a blocked route and a rejected request always agree.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // On a fresh page load we may hold a token but not yet know who it belongs
  // to. Await the boot check first, or a valid session would look logged out
  // and get redirected away from the page the user asked for.
  if (!auth.ready) await auth.init()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // Remember where they were going so login can return them there.
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  if (to.meta.screenCode && auth.isAuthenticated && !auth.can(to.meta.screenCode, 'view')) {
    // Bail out instead of redirecting if even the dashboard is denied —
    // redirecting to it would just bounce straight back here forever.
    return to.name === 'dashboard' ? false : { name: 'dashboard' }
  }

  return true
})

export default router
