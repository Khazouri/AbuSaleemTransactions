import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

/**
 * Application routes and the navigation guard.
 *
 * Route components are imported lazily (`() => import(...)`) so each screen
 * ships as its own JS chunk — the login page doesn't download the dashboard.
 *
 * `meta` drives access:
 *   requiresAuth  must be signed in
 *   guestOnly     must NOT be signed in (e.g. the login page)
 */
const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guestOnly: true },
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('../views/DashboardView.vue'),
    meta: { requiresAuth: true },
  },

  // Landing path goes to the dashboard; the guard sends guests to /login.
  { path: '/', redirect: '/dashboard' },

  // Catch-all for unknown URLs. Once the real screens exist this should show a
  // proper 404 page rather than silently redirecting.
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  // Web history = clean URLs with no '#'. Note this needs the dev server (and
  // any production host) to fall back to index.html for unknown paths.
  history: createWebHistory(),
  routes,
})

/**
 * Global guard — runs before every navigation.
 *
 * Returning a location object redirects; returning true allows the navigation.
 *
 * Stage 9 extends this to check the screen-level permission matrix, so a user
 * can't reach a screen their role has no can_view flag for.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // On a fresh page load we may hold a token but not yet know who it belongs
  // to. Await the boot check first, or we'd wrongly treat a valid session as
  // logged out and redirect away from the page the user asked for.
  if (!auth.ready) await auth.init()

  // Not signed in but the route needs it: send to login, remembering where
  // they were headed so we can return them after a successful login.
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Already signed in and hitting the login page: nothing to do there.
  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
