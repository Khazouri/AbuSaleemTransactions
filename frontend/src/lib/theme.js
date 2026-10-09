import { ref, computed } from 'vue'

/**
 * Light / dark theme.
 *
 * Deliberately shaped like i18n/index.js: a stored preference, one `apply`
 * function that is the only writer of the <html> attribute, and an immediate
 * call at import time so the first paint is already correct.
 *
 * The palettes themselves live in style.css, not here — this module only
 * decides WHICH one applies. That split is what makes the no-JS/first-frame
 * case work: with no stored preference we write no attribute at all and the
 * `prefers-color-scheme` block in the stylesheet takes over, so a visitor on a
 * dark OS never sees a flash of white while the bundle loads.
 */

/** What the user has asked for. `system` means "whatever the OS says". */
export const THEME_PREFERENCES = ['system', 'light', 'dark']

/** localStorage key remembering the choice between visits. */
const THEME_KEY = 'abs_theme'

/**
 * Guarded because this module is imported at bundle-eval time: an environment
 * without matchMedia (an SSR pass, an older test runner) must not take the
 * whole app down over a colour scheme.
 */
const darkQuery = typeof window !== 'undefined' && window.matchMedia
  ? window.matchMedia('(prefers-color-scheme: dark)')
  : null

const stored = localStorage.getItem(THEME_KEY)

/** The stored preference — `system` unless the user has explicitly chosen. */
export const preference = ref(THEME_PREFERENCES.includes(stored) ? stored : 'system')

/** What the OS currently reports; kept in a ref so `theme` recomputes on change. */
const systemPrefersDark = ref(darkQuery?.matches ?? false)

/**
 * The theme actually on screen — `light` or `dark`, never `system`.
 * This is what the UI should read (which icon the toggle shows, and so on).
 */
export const theme = computed(() => (
  preference.value === 'system'
    ? (systemPrefersDark.value ? 'dark' : 'light')
    : preference.value
))

/**
 * Choose a theme: remembers it and stamps <html data-theme>.
 *
 * `system` REMOVES the attribute rather than writing a resolved value, which
 * is what hands control back to the stylesheet's media query — including for
 * the next visit, before any of this code has run.
 *
 * @param {'system'|'light'|'dark'} next
 */
export function applyTheme(next) {
  if (!THEME_PREFERENCES.includes(next)) return

  preference.value = next
  localStorage.setItem(THEME_KEY, next)

  const html = document.documentElement
  if (next === 'system') {
    html.removeAttribute('data-theme')
  } else {
    html.setAttribute('data-theme', next)
  }
}

/**
 * Flip to the opposite of what is currently on screen.
 *
 * Reading `theme` rather than `preference` is the point: for someone still on
 * `system`, one click has to move them off it in the direction they can see,
 * not to whichever value happens to be next in a list.
 */
export function toggleTheme() {
  applyTheme(theme.value === 'dark' ? 'light' : 'dark')
}

// A `system` user should follow the OS while the tab is open, not just at load.
darkQuery?.addEventListener('change', (event) => {
  systemPrefersDark.value = event.matches
})

// Apply immediately at import time, so the first paint is already themed.
applyTheme(preference.value)
