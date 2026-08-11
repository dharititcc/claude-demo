import type { CapacitorConfig } from '@capacitor/cli'

/**
 * Capacitor wraps the built web app (dist/) in a native iOS/Android shell, so
 * the phone app runs the exact same React bundle the browser does — one
 * codebase, identical screens and logic.
 *
 * There is intentionally no `server.url` here: the app ships the web build
 * *inside* it (offline shell, fast start) and talks to the hosted API over
 * HTTPS via VITE_API_URL — it does NOT load the site from a remote URL. Point
 * VITE_API_URL at the hosted API when building for the app; on a device
 * "localhost" is the phone itself, not your server.
 */
const config: CapacitorConfig = {
  // Change to your real reverse-domain bundle id before shipping to the stores.
  appId: 'co.itcc.saasplatform',
  appName: 'SaaS Platform',
  webDir: 'dist',
}

export default config
