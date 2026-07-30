import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AppLayout from '../layouts/AppLayout.vue'
import PlaceholderView from '../views/PlaceholderView.vue'

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

/**
 * Screens that exist in the menu but whose real UI arrives in a later stage.
 * Each becomes a route rendering PlaceholderView, so every sidebar link works
 * today. As each stage lands, its entry moves out of this list and gets a real
 * component.
 *
 * Paths are relative to the layout's "/" parent, hence no leading slash.
 * Format: [screenCode, path]
 */
const placeholderScreens = [
  ['meetings', 'meetings'],                                  // Stage 20
  ['decisions', 'decisions'],                                // Stage 21
  // NB: `departments`, `users` and `roles_permissions` are NOT here — they're
  // built (Stages 6-8) and have real components below.
  ['reports', 'reports'],                                    // Stage 24
  ['audit_log', 'audit-log'],                                // Stage 22
  ['notifications', 'notifications'],                        // Stage 23
  ['backup', 'backup'],                                      // later
  ['user_guide', 'guide'],                                   // later
]

/*
 * Note the two screens absent from that list: transaction_details
 * ("/transactions/:id") and notes_attachments. They need a specific
 * transaction id, so they can't be linked from a static menu — the sidebar
 * filters them out and they get real routes in Stage 15.
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
        // Stage 11 — searchable, paginated transaction work queue.
        path: 'transactions',
        name: 'transactions',
        component: () => import('../views/TransactionsView.vue'),
        meta: { screenCode: 'transactions' },
      },
      {
        // Stage 13 — full transaction intake and reference allocation.
        path: 'transactions/create',
        name: 'transaction_intake',
        component: () => import('../views/TransactionIntakeView.vue'),
        meta: { screenCode: 'transaction_intake' },
      },
      {
        // Stage 15 — transaction workspace with workflow actions and timeline.
        path: 'transactions/:id',
        name: 'transaction_details',
        component: () => import('../views/TransactionDetailView.vue'),
        meta: { screenCode: 'transaction_details' },
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
      // Stage 18 — role-specific queues backed by one reusable approval view.
      ...[
        ['reviewer_approval', 'reviewer'],
        ['committee_head_approval', 'committee-head'],
        ['admin_manager_approval', 'admin-manager'],
        ['ministry_approval', 'ministry'],
        ['authority_approval', 'authority'],
        ['final_approval', 'final'],
      ].map(([screenCode, level]) => ({
        path: `approvals/${level}`,
        name: screenCode,
        component: () => import('../views/ApprovalQueueView.vue'),
        meta: { screenCode, approvalLevel: level },
      })),
      // Expand the list above into one placeholder route each.
      ...placeholderScreens.map(([screenCode, path]) => ({
        path,
        name: screenCode,
        component: PlaceholderView,
        meta: { screenCode },
      })),
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
