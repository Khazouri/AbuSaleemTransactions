import api from './api'

/**
 * Stage 24 — fetch an export endpoint and hand the file to the browser.
 *
 * Exports can't be a plain <a href>: every API route is behind a Sanctum
 * bearer token, and a navigation request carries no Authorization header. So
 * the file comes back through the same axios instance as everything else,
 * as a blob, and is handed to the browser through a temporary object URL.
 */

/** Pull the server's filename out of Content-Disposition, if it gave one. */
function filenameFrom(disposition, fallback) {
  if (!disposition) return fallback

  // RFC 5987 form first — it's the one that survives non-ASCII names.
  const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition)
  if (encoded) return decodeURIComponent(encoded[1])

  const plain = /filename="?([^";]+)"?/i.exec(disposition)
  return plain ? plain[1] : fallback
}

async function decodeErrorBody(error) {
  const body = error?.response?.data
  if (!(body instanceof Blob)) return

  try {
    error.response.data = JSON.parse(await body.text())
  } catch {
    // Not JSON (an HTML error page, say) — leave the blob in place rather
    // than replacing a real response with a misleading empty object.
  }
}

/**
 * @param {string} url      API path, e.g. '/reports/requests/export'
 * @param {object} params   Query parameters (filters, format, locale)
 * @param {string} fallback Filename to use if the server didn't name the file
 */
export async function downloadExport(url, params, fallback) {
  let response
  try {
    response = await api.get(url, { params, responseType: 'blob' })
  } catch (error) {
    // responseType:'blob' applies to error bodies too, so a 403 or a 422
    // arrives as an unreadable Blob. Decode it back into the normal JSON
    // shape, or the caller's error handling would show nothing at all.
    await decodeErrorBody(error)
    throw error
  }

  const objectUrl = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filenameFrom(response.headers['content-disposition'], fallback)
  document.body.appendChild(link)
  link.click()
  link.remove()

  // Revoking immediately can cancel the download in some browsers; a tick
  // later the transfer has already been handed off.
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
}
