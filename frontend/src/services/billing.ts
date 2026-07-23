import { api } from './api'
import type { BillingInterval, BillingOverview, Plan, StripeInvoice } from '@/types'

export const billingService = {
  async plans(): Promise<Plan[]> {
    const { data } = await api.get<{ data: Plan[] }>('/v1/billing/plans')
    return data.data
  },

  async overview(): Promise<BillingOverview> {
    const { data } = await api.get<{ data: BillingOverview }>('/v1/billing')
    return data.data
  },

  async invoices(): Promise<StripeInvoice[]> {
    const { data } = await api.get<{ data: StripeInvoice[] }>('/v1/billing/invoices')
    return data.data
  },

  /**
   * A Stripe SetupIntent client secret, used by Stripe.js to collect card
   * details in the browser. Raw card data never touches our servers — that is
   * what keeps this application out of PCI scope.
   */
  async setupIntent(): Promise<string> {
    const { data } = await api.get<{ data: { client_secret: string } }>('/v1/billing/setup-intent')
    return data.data.client_secret
  },

  /**
   * Subscribe. `paymentMethod` is a Stripe payment-method id produced by
   * Stripe.js — never a card number.
   */
  async subscribe(payload: {
    plan: string
    interval: BillingInterval
    payment_method?: string
    coupon?: string
  }): Promise<BillingOverview> {
    const { data } = await api.post<{ data: BillingOverview }>('/v1/billing/subscribe', payload)
    return data.data
  },

  async swapPlan(payload: { plan: string; interval: BillingInterval }): Promise<BillingOverview> {
    const { data } = await api.put<{ data: BillingOverview }>('/v1/billing/subscription', payload)
    return data.data
  },

  /** Cancels at period end — access continues until then (grace period). */
  async cancel(): Promise<BillingOverview> {
    const { data } = await api.delete<{ data: BillingOverview }>('/v1/billing/subscription')
    return data.data
  },

  async resume(): Promise<BillingOverview> {
    const { data } = await api.post<{ data: BillingOverview }>('/v1/billing/subscription/resume')
    return data.data
  },
}
