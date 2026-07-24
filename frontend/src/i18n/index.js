import { createI18n } from 'vue-i18n'
import ar from '../locales/ar.json'
import en from '../locales/en.json'

/**
 * Internationalisation and text direction.
 *
 * Arabic is the primary language, so the app defaults to `ar` + RTL. English
 * is available as a toggle and flips the whole layout to LTR.
 *
 * Direction is deliberately handled HERE alongside the locale, rather than in
 * a component: they must never disagree. Setting `dir` on <html> lets CSS
 * logical properties (margin-inline-start, etc.) mirror the entire UI
 * automatically, which is why the layout needs no RTL-specific rules.
 */

/** Locales we ship, each with the text direction it implies. */
export const SUPPORTED_LOCALES = {
  ar: { dir: 'rtl', label: 'العربية' },
  en: { dir: 'ltr', label: 'English' },
}

/** localStorage key remembering the user's choice between visits. */
const LOCALE_KEY = 'abs_locale'

// Restore the saved locale, ignoring anything we no longer support.
const saved = localStorage.getItem(LOCALE_KEY)
const initialLocale = SUPPORTED_LOCALES[saved] ? saved : 'ar'

const i18n = createI18n({
  // legacy:false selects the Composition API mode, which is what lets
  // components call useI18n() and keeps `locale` reactive.
  legacy: false,
  globalInjection: true,
  locale: initialLocale,
  // Fall back to English if an Arabic key is ever missing, so the UI shows
  // real text rather than the raw key name.
  fallbackLocale: 'en',
  messages: { ar, en },
})

/**
 * Switch language: updates vue-i18n, remembers the choice, and flips the
 * document's direction and lang attributes to match.
 *
 * @param {'ar'|'en'} locale
 */
export function applyLocale(locale) {
  if (!SUPPORTED_LOCALES[locale]) return

  i18n.global.locale.value = locale
  localStorage.setItem(LOCALE_KEY, locale)

  // <html lang dir> — drives CSS logical properties, native form controls,
  // text selection behaviour and screen-reader pronunciation.
  const html = document.documentElement
  html.setAttribute('lang', locale)
  html.setAttribute('dir', SUPPORTED_LOCALES[locale].dir)
}

// Apply immediately at import time, so the very first paint is already in the
// right language and direction (no flash of the wrong layout).
applyLocale(initialLocale)

export default i18n
