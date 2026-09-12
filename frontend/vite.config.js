import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],

  server: {
    // The dev port is pinned, and strictly.
    //
    // Laravel's config/cors.php allows exactly ONE origin — env('FRONTEND_URL')
    // — and the browser compares it character-for-character. By default Vite
    // silently moves to the next free port when 5173 is taken (5174, 5175, …),
    // which hands the browser an origin the API does not allow. Every call is
    // then blocked before the response is readable, so axios rejects with no
    // `response` at all and the login screen reports "server unreachable"
    // while the API is up and the credentials are fine.
    //
    // strictPort turns that silent drift into a loud "Port 5173 is already in
    // use" at startup, which is a two-second diagnosis instead of an hour.
    // Keep this in step with FRONTEND_URL in the Laravel .env.
    port: 5173,
    strictPort: true,
  },
})
