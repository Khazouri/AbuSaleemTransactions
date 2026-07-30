import { useAuthStore } from '../stores/auth'

/**
 * v-can="'users.edit'" — hides an element unless the signed-in user's role
 * grants that screen/action (see auth store's `can()`). Stage 9's
 * button-level half of permission enforcement: mirrors the same
 * screen_role_permissions data the router guard and the API's
 * screen.permission middleware read, so what's shown never promises more
 * than the API will actually allow.
 *
 * UX only — hiding an element here does not stop a crafted request; the
 * middleware is the real enforcement. `display: none` (not v-if/unmount) so
 * toggling a permission doesn't need to remount the element.
 */
function apply(el, binding) {
  const auth = useAuthStore()
  const [screenCode, action] = String(binding.value ?? '').split('.')
  el.style.display = auth.can(screenCode, action) ? '' : 'none'
}

export default {
  mounted: apply,
  updated: apply,
}
