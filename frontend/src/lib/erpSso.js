/**
 * ERP single sign-on — the browser half of an OAuth 2.0 authorization-code
 * flow with PKCE (RFC 7636), the EmployeePlus ERP as the authorization server.
 *
 * Only a one-time code ever comes back through the URL; the backend redeems it
 * with the client secret (ErpAuthController). Two values stay in this tab's
 * sessionStorage between leaving for the ERP and coming back:
 *   - `state`, compared on return, so a link carrying someone else's code
 *     cannot sign this browser into their account (login CSRF, RFC 6749 §10.12);
 *   - the PKCE `verifier`, so a code intercepted on the way back is useless
 *     without it.
 */
import api from './api'

const KEY = 'erp_sso'

let configPromise = null

/**
 * The ERP client's public settings, or null where the backend has none
 * configured (it answers 404). Asked once per page load.
 */
export function erpConfig() {
  configPromise ??= api.get('/auth/erp/config').then(({ data }) => data, () => null)
  return configPromise
}

function randomToken() {
  return base64Url(crypto.getRandomValues(new Uint8Array(32)))
}

function base64Url(bytes) {
  return btoa(String.fromCharCode(...new Uint8Array(bytes)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Leave for the ERP's sign-in page. `intent` is 'login' (signed out) or
 * 'link' (signed in, attaching the ERP account to this one).
 */
export async function startErpSignIn(intent) {
  const config = await erpConfig()
  if (!config) throw new Error('erp_unavailable')

  const state = randomToken()
  const verifier = randomToken()
  const challenge = base64Url(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier)))
  sessionStorage.setItem(KEY, JSON.stringify({ state, verifier, intent }))

  const url = new URL(config.authorize_url)
  url.search = new URLSearchParams({
    response_type: 'code',
    client_id: config.client_id,
    redirect_uri: config.redirect_uri,
    scope: 'openid',
    state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
  })
  window.location.assign(url)
}

/**
 * Read the ERP's answer once. The stored pair is removed whatever happens, so
 * a code can never be replayed from this tab. Throws 'erp_denied' when the
 * ERP refused, and 'erp_state' when the answer is not the one this tab asked for.
 */
export function takeErpReturn(query) {
  let pending = null
  try {
    pending = JSON.parse(sessionStorage.getItem(KEY))
  } catch {
    // Corrupt or blocked storage reads as "nothing pending" below.
  }
  sessionStorage.removeItem(KEY)

  if (query.error) throw new Error('erp_denied')
  if (!pending?.state || !query.code || query.state !== pending.state) throw new Error('erp_state')

  return { code: query.code, verifier: pending.verifier, intent: pending.intent }
}
