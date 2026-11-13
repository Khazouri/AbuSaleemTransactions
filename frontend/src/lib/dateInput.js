// High open items (2026-11-13) — a `datetime-local` value has no zone, and the
// API reads a zone-less time as UTC, so a meeting typed as 10:00 in Tripoli
// came back as 12:00. These two convert at the edge of the form.

/** The input's local wall time as a UTC instant, or null when empty. */
export function toApiDateTime(local) {
  return local ? new Date(local).toISOString() : null
}

/** An API instant as the `YYYY-MM-DDTHH:mm` a `datetime-local` input shows. */
export function toLocalInput(iso) {
  const at = new Date(iso)
  return new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}
