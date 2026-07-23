import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js'
import toast from 'react-hot-toast'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { billingService } from '@/services/billing'
import { apiErrorMessage } from '@/hooks/useAuth'
import { useAuthStore } from '@/store/auth'
import { useThemeStore } from '@/store/theme'
import { stripeConfigured, stripePromise } from '@/lib/stripe'
import { formatDate } from '@/lib/date'
import type { BillingInterval, Plan } from '@/types'

/**
 * Takes a card and starts a subscription.
 *
 * Only for the *first* subscription — changing an existing one is a swap, which
 * needs no card because Stripe already has one on file.
 *
 * The card is collected by Stripe's own iframe and exchanged for a payment-method
 * id inside the browser; the only thing that reaches our API is that id. Raw card
 * details never touch our servers, which is what keeps this app out of PCI scope.
 */
export function SubscribeDialog({
  open,
  plan,
  interval,
  trialEndsAt,
  onClose,
}: {
  open: boolean
  plan: Plan | null
  interval: BillingInterval
  /**
   * The organization's existing trial end, when it is still running.
   *
   * It takes precedence over the plan's trial length: the API preserves the
   * remaining trial rather than restarting it, so an org 7 days into a trial
   * gets 7 more days, not a fresh 14. Advertising the plan's number here would
   * promise days the customer will not get.
   */
  trialEndsAt: string | null
  onClose: () => void
}) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const theme = useThemeStore((s) => s.theme)

  // A SetupIntent is single-use, so it is fetched per opening rather than
  // cached — a stale secret would be rejected by Stripe.
  const intent = useQuery({
    queryKey: ['billing', 'setup-intent', orgSlug, plan?.id],
    queryFn: billingService.setupIntent,
    enabled: open && stripeConfigured && plan !== null,
    gcTime: 0,
    staleTime: 0,
    retry: false,
  })

  if (!open || plan === null) return null

  // A trial already under way wins; only a fresh subscriber gets the plan's own
  // trial length. Mirrors the branching in BillingService::subscribe().
  const runningTrialEnd = trialEndsAt && new Date(trialEndsAt) > new Date() ? trialEndsAt : null
  const trialLabel = runningTrialEnd
    ? `Free trial until ${formatDate(runningTrialEnd)}`
    : plan.trial_days > 0
      ? `${plan.trial_days}-day free trial`
      : null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden />

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="subscribe-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-md overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-start justify-between">
          <div>
            <h2 id="subscribe-dialog-title" className="text-lg font-semibold">
              Subscribe to {plan.name}
            </h2>
            <p className="text-sm text-muted-foreground">
              Billed {interval === 'annual' ? 'yearly' : 'monthly'}
              {trialLabel && ` · ${trialLabel}`}
            </p>
          </div>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        {!stripeConfigured ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            Card payments are not configured. Set VITE_STRIPE_KEY to enable them.
          </p>
        ) : intent.isLoading ? (
          <div className="flex h-40 items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : intent.isError || !intent.data ? (
          <p className="py-6 text-center text-sm text-destructive">
            {apiErrorMessage(intent.error, 'Could not start checkout. Please try again.')}
          </p>
        ) : (
          <Elements
            stripe={stripePromise}
            options={{
              clientSecret: intent.data,
              appearance: { theme: theme === 'dark' ? 'night' : 'stripe' },
            }}
          >
            <SubscribeForm
              plan={plan}
              interval={interval}
              hasTrial={trialLabel !== null}
              onClose={onClose}
            />
          </Elements>
        )}
      </div>
    </div>
  )
}

/**
 * Inside <Elements>, so it can reach the Stripe instance and the mounted card
 * fields via the hooks.
 */
function SubscribeForm({
  plan,
  interval,
  hasTrial,
  onClose,
}: {
  plan: Plan
  interval: BillingInterval
  hasTrial: boolean
  onClose: () => void
}) {
  const stripe = useStripe()
  const elements = useElements()
  const queryClient = useQueryClient()
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  const [error, setError] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  const subscribe = useMutation({
    mutationFn: (paymentMethod: string) =>
      billingService.subscribe({ plan: plan.slug, interval, payment_method: paymentMethod }),
    onSuccess: () => {
      toast.success(`Subscribed to ${plan.name}.`)
      queryClient.invalidateQueries({ queryKey: ['billing', orgSlug] })
      onClose()
    },
    onError: (e) => setError(apiErrorMessage(e, 'Could not start the subscription.')),
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()

    // Stripe.js may still be loading; submitting now would silently do nothing.
    if (!stripe || !elements) return

    setError(null)
    setConfirming(true)

    // Saves the card against the SetupIntent and runs 3-D Secure if the bank
    // asks for it. 'if_required' keeps the customer in the dialog unless their
    // bank insists on a redirect.
    const { error: stripeError, setupIntent } = await stripe.confirmSetup({
      elements,
      redirect: 'if_required',
    })

    setConfirming(false)

    if (stripeError) {
      // Stripe's messages are customer-facing and specific ("Your card was
      // declined"), so they are shown as-is rather than replaced.
      setError(stripeError.message ?? 'Could not verify your card.')

      return
    }

    const paymentMethod =
      typeof setupIntent?.payment_method === 'string'
        ? setupIntent.payment_method
        : setupIntent?.payment_method?.id

    if (!paymentMethod) {
      setError('Your card could not be saved. Please try again.')

      return
    }

    subscribe.mutate(paymentMethod)
  }

  const pending = confirming || subscribe.isPending

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      <PaymentElement options={{ layout: 'tabs' }} />

      {error && <p className="text-sm text-destructive">{error}</p>}

      <Button type="submit" className="w-full" loading={pending} disabled={!stripe || pending}>
        {hasTrial ? 'Start free trial' : 'Subscribe'}
      </Button>

      <p className="text-center text-xs text-muted-foreground">
        Your card details go straight to Stripe and are never sent to our servers.
      </p>
    </form>
  )
}
