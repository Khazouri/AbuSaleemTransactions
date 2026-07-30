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
  ['transactions', 'transactions'],                          // Stage 11
  ['transaction_intake', 'transactions/create'],             // Stage 13
  ['meetings', 'meetings'],                                  // Stage 20
  ['decisions', 'decisions'],                                // Stage 21
  ['reviewer_approval', 'approvals/reviewer'],               // Stage 18
  ['committee_head_approval', 'approvals/committee-head'],   // Stage 18
  ['admin_manager_approval', 'approvals/admin-manager'],     // Stage 18
  ['ministry_approval', 'approvals/ministry'],               // Stage 18
  ['authority_approval', 'approvals/authority'],             // Stage 18
  ['final_approval', 'approvals/final'],                     // Stage 18
  // NB: `departments`, `users` and `roles_permissions` are NOT here — they're
  // built (Stages 6-8) and have real components below.
  ['settings', 'settings'],                                  // Stage 10
  ['reports', 'reports'],                                    // Stage 24
  ['audit_log', 'audit-log'],                                // Stage 22
  ['notifications', 'notifications'],                        // Stage 23
  ['templates', 'templates'],                                // Stage 10
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
 * Stage 9 extends this to check `meta.screenCode` against the user's
 * permission matrix, so a user can't reach a screen their role can't view.
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

  return true
})

export default router
