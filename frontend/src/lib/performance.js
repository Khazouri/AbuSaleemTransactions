/**
 * Stage 81 — the shared bits of [D] Art. 106's indicators, Appendix 10's
 * warnings and Appendix 11's board.
 *
 * Deliberately thin: the labels themselves are rendered SERVER-side and travel
 * beside every number, so this file holds only how to display a value, never
 * what to call it. That is the same call Stage 80 made for its registers, and
 * it is what keeps the screen and an exported copy naming one indicator
 * identically — switching locale re-fetches rather than re-formatting.
 */

/** A value the server could not measure reads as absent, never as zero. */
export function formatIndicatorValue(value, unit, locale, noneLabel = '—') {
  if (value === null || value === undefined) return noneLabel

  const formatter = new Intl.NumberFormat(locale === 'ar' ? 'ar-LY' : 'en-GB', {
    maximumFractionDigits: 1,
  })

  if (unit === 'percent') return `${formatter.format(value)}%`
  if (unit === 'days') return formatter.format(value)

  return formatter.format(value)
}

/**
 * Appendix 11 does not claim its buckets partition the population, and the
 * payload says which do — bucket 1 counts arrivals during the period and
 * bucket 9 cross-cuts every other bucket, so both are marked rather than
 * rendered as if they were disjoint slices of one whole.
 */
export function bucketScopeClass(scope) {
  if (scope === 'period') return 'scope-period'
  if (scope === 'cross_cutting') return 'scope-cross'
  return 'scope-live'
}

/** Stage 71's own ladder, reused so one file is never late in two colours. */
export function timelinessClass(level) {
  return level ? `level-${level}` : ''
}
