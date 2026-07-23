import { loadStripe, type Stripe } from '@stripe/stripe-js'

/**
 * The Stripe.js singleton.
 *
 * Loaded once at module scope rather than per render: loadStripe() injects a
 * script tag, and calling it inside a component would re-run on every mount.
 *
 * Resolves to null when no publishable key is configured, so a deployment
 * without Stripe degrades to "card payments are not configured" instead of
 * throwing inside the checkout dialog.
 */
const key = import.meta.env.VITE_STRIPE_KEY as string | undefined

export const stripeConfigured = Boolean(key)

export const stripePromise: Promise<Stripe | null> = key
  ? loadStripe(key)
  : Promise.resolve(null)
