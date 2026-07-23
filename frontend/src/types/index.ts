/**
 * API contract types. These mirror the Laravel API Resources in
 * `backend/app/Http/Resources`, and are the single source of truth for
 * response shapes across the SPA.
 */

export interface User {
  id: number
  name: string
  email: string
  avatar: string | null
  phone: string | null
  locale: string
  timezone: string
  status: 'active' | 'suspended' | 'invited'
  is_super_admin: boolean
  email_verified: boolean
  two_factor_enabled: boolean
  last_login_at: string | null
  created_at: string | null
  organizations?: Organization[]
}

export type OrganizationStatus = 'trial' | 'active' | 'suspended' | 'cancelled'

export interface Organization {
  id: string
  name: string
  slug: string
  logo: string | null
  timezone: string
  currency: string
  language: string
  status: OrganizationStatus
  on_trial: boolean
  trial_ends_at: string | null
  created_at: string | null
  is_owner?: boolean
}

/** Roles are organization-scoped; Super Admin is a platform-level flag. */
export type Role = 'owner' | 'admin' | 'manager' | 'employee' | 'viewer'

export interface OrganizationContext {
  organization: Organization
  role: Role | null
  permissions: string[]
  is_super_admin: boolean
}

export interface Session {
  id: number
  name: string
  last_used_at: string | null
  created_at: string | null
  current: boolean
}

export interface LoginHistoryEntry {
  id: number
  ip_address: string | null
  user_agent: string | null
  successful: boolean
  reason: string | null
  attempted_at: string | null
}

// ─── Requests ───

export interface LoginCredentials {
  email: string
  password: string
  device_name?: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  organization_name: string
  timezone?: string
}

// ─── Responses ───

export interface LoginResponse {
  message: string
  data: {
    user: User
    organizations: Organization[]
    token: string
  }
}

/** Returned with HTTP 202 when the account has 2FA enabled. */
export interface TwoFactorChallengeResponse {
  message: string
  data: {
    two_factor_required: true
    challenge_token: string
  }
}

/**
 * A password check either signs you in or owes a second factor. Modelling that
 * as a union forces callers to handle the challenge instead of reaching for a
 * token that may not be there.
 */
export type LoginResult =
  | { status: 'authenticated'; session: LoginResponse['data'] }
  | { status: 'two_factor_required'; challengeToken: string }

export interface TwoFactorChallengePayload {
  challengeToken: string
  /** Six digits from the authenticator. */
  code?: string
  /** A single-use recovery code; consumed when accepted. */
  recoveryCode?: string
}

/** Current two-factor state for the signed-in user. */
export interface TwoFactorStatus {
  enabled: boolean
  /** A secret exists but was never confirmed, so the account is NOT protected. */
  pending_confirmation: boolean
  confirmed_at: string | null
  recovery_codes_remaining: number
}

export interface TwoFactorEnrolment {
  secret: string
  /** otpauth:// URI — render as a QR code. */
  otpauth_uri: string
}

export interface RegisterResponse {
  message: string
  data: {
    user: User
    organization: Organization
    token: string
  }
}

// ─── Customers ───

export type CustomerStatus = 'lead' | 'active' | 'inactive' | 'churned'

export interface Tag {
  id: number
  name: string
  slug: string
  color: string
}

export interface Note {
  id: number
  body: string
  user_id: number
  created_at: string | null
}

export interface Attachment {
  id: number
  filename: string
  mime_type: string | null
  size: number
  url: string
  user_id: number
  created_at: string | null
}

export interface CustomerAddress {
  line1: string | null
  line2: string | null
  city: string | null
  state: string | null
  postal_code: string | null
  country: string | null
}

export interface Customer {
  id: number
  /** Issued by the server (C-000001); never sent by the client. */
  customer_number: string | null
  name: string
  email: string | null
  phone: string | null
  mobile: string | null
  company: string | null
  trading_name: string | null
  tax_number: string | null
  registration_number: string | null
  industry: string | null
  website: string | null
  status: CustomerStatus
  /** The billing address — the original address_* columns. */
  address: CustomerAddress
  shipping_address: CustomerAddress
  timezone: string | null
  currency: string | null
  logo_path: string | null
  lifetime_value: number
  owner_id: number | null
  tags?: Tag[]
  notes?: Note[]
  attachments?: Attachment[]
  /** Loaded in full on the detail screen only. */
  contacts?: CustomerContact[]
  /** The list screen loads just this one, not every contact. */
  primary_contact?: CustomerContact | null
  notes_count?: number
  contacts_count?: number
  projects_count?: number
  deleted_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type ContactStatus = 'active' | 'inactive'

export interface CustomerContact {
  id: number
  customer_id: number
  first_name: string
  last_name: string | null
  /** Composed server-side so every screen renders a name identically. */
  full_name: string
  email: string | null
  phone: string | null
  mobile: string | null
  department: string | null
  job_title: string | null
  notes: string | null
  is_primary: boolean
  status: ContactStatus
  created_at: string | null
  updated_at: string | null
}

/** Absent key = leave unchanged. `is_primary` is intent; the server decides. */
export type CustomerContactPayload = Partial<{
  first_name: string
  last_name: string | null
  email: string | null
  phone: string | null
  mobile: string | null
  department: string | null
  job_title: string | null
  notes: string | null
  status: ContactStatus
  is_primary: boolean
}>

export interface CustomerPayload {
  name: string
  email?: string | null
  phone?: string | null
  mobile?: string | null
  company?: string | null
  trading_name?: string | null
  tax_number?: string | null
  registration_number?: string | null
  industry?: string | null
  website?: string | null
  status?: CustomerStatus
  lifetime_value?: number | null
  tags?: string[]

  // Billing address.
  address_line1?: string | null
  address_line2?: string | null
  city?: string | null
  state?: string | null
  postal_code?: string | null
  country?: string | null

  // Shipping address.
  shipping_address_line1?: string | null
  shipping_address_line2?: string | null
  shipping_city?: string | null
  shipping_state?: string | null
  shipping_postal_code?: string | null
  shipping_country?: string | null

  timezone?: string | null
  currency?: string | null
}

/** Query parameters accepted by the customers list endpoint. */
export interface CustomerFilters {
  q?: string
  status?: string
  industry?: string
  tag?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── Customer invoices ───
// The organization's own sales documents. Nothing to do with BillingOverview,
// which is what the organization pays us for the platform.

/** What is stored. `display_status` additionally carries the derived states. */
export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'void'
export type InvoiceDisplayStatus = InvoiceStatus | 'overdue' | 'partial'

export interface InvoiceItem {
  id: number
  description: string
  quantity: number
  unit_price: number
  tax_rate: number
  /** Stored server-side: the figure the customer was actually shown. */
  line_total: number
  position: number
}

export interface Invoice {
  id: number
  customer_id: number
  number: string
  status: InvoiceStatus
  /** Folds in overdue/partial, which are derived rather than stored. */
  display_status: InvoiceDisplayStatus
  is_overdue: boolean
  /** Only a draft may have its figures changed. */
  is_editable: boolean
  issue_date: string
  due_date: string
  paid_at: string | null
  currency: string
  subtotal: number
  tax_total: number
  total: number
  amount_paid: number
  balance: number
  notes: string | null
  terms: string | null
  items?: InvoiceItem[]
  customer?: Pick<Customer, 'id' | 'name' | 'customer_number'>
  created_at: string | null
  updated_at: string | null
}

export interface InvoiceItemPayload {
  description: string
  quantity: number
  unit_price: number
  tax_rate?: number
}

/** Note what is absent: number, status and totals are all server-owned. */
export interface InvoicePayload {
  issue_date?: string
  due_date?: string
  currency?: string
  notes?: string | null
  terms?: string | null
  items?: InvoiceItemPayload[]
}

export interface InvoiceFilters {
  q?: string
  status?: InvoiceDisplayStatus
  customer_id?: number
  due_after?: string
  due_before?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── Team ───

/** A user as seen from inside one organization: identity + the role held here. */
export interface Member {
  id: number
  name: string
  email: string
  avatar: string | null
  status: string
  email_verified: boolean
  last_login_at: string | null
  role: Role | null
  is_owner: boolean
  joined_at: string | null
}

export interface Invitation {
  id: number
  email: string
  role: Role
  expires_at: string
  accepted_at: string | null
  pending: boolean
  created_at: string | null
}

/** What the invitee sees before signing in. Never includes the token. */
export interface InvitationPreview {
  email: string
  role: Role
  organization_name: string
  invited_by: string | null
  expires_at: string
}

// ─── Projects & Tasks ───

export type ProjectStatus = 'planning' | 'active' | 'on_hold' | 'completed' | 'cancelled'

export interface Project {
  id: number
  name: string
  slug: string
  description: string | null
  status: ProjectStatus
  color: string
  customer: { id: number; name: string } | null
  customer_id: number | null
  owner_id: number | null
  starts_on: string | null
  due_on: string | null
  completed_at: string | null
  is_overdue: boolean
  budget: number | null
  tasks_count?: number
  completed_tasks_count?: number
  progress?: number
  created_at: string | null
  updated_at: string | null
}

export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'done'
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent'

export interface Label {
  id: number
  name: string
  slug: string
  color: string
}

export interface Task {
  id: number
  title: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  project_id: number | null
  project: { id: number; name: string; color: string } | null
  parent_id: number | null
  assignee_id: number | null
  created_by: number | null
  due_on: string | null
  completed_at: string | null
  is_overdue: boolean
  position: number
  tracked_seconds: number
  estimated_minutes: number | null
  labels?: Label[]
  subtasks?: Task[]
  subtasks_count?: number
  comments?: Comment[]
  time_entries?: TimeEntry[]
  created_at: string | null
  updated_at: string | null
}

export interface BoardColumn {
  status: TaskStatus
  count: number
  tasks: Task[]
}

// ─── Calendar ───

export type EventType = 'event' | 'meeting' | 'reminder' | 'deadline'

/** An expanded occurrence returned by the calendar window endpoint. */
export interface EventOccurrence {
  event_id: number
  series_id: number | null
  title: string
  description: string | null
  location: string | null
  type: EventType
  color: string
  all_day: boolean
  starts_at: string
  ends_at: string
  is_recurring: boolean
  is_exception: boolean
}

// ─── Audit ───

export interface AuditLogEntry {
  id: number
  log_name: string
  description: string
  event: string
  subject_type: string
  subject_id: number | null
  causer_id: number | null
  changes: { attributes?: Record<string, unknown>; old?: Record<string, unknown> }
  created_at: string | null
}

export interface TimeEntry {
  id: number
  task_id: number
  user_id: number
  description: string | null
  started_at: string | null
  ended_at: string | null
  running: boolean
  seconds: number
  billable: boolean
  created_at: string | null
}

export interface Comment {
  id: number
  body: string
  user_id: number
  created_at: string | null
  updated_at: string | null
}

export interface TaskPayload {
  title: string
  description?: string | null
  project_id?: number | null
  parent_id?: number | null
  status?: TaskStatus
  priority?: TaskPriority
  assignee_id?: number | null
  due_on?: string | null
  estimated_minutes?: number | null
  labels?: string[]
}

// ─── Billing ───

export type BillingInterval = 'monthly' | 'annual'

export interface Plan {
  id: number
  name: string
  slug: string
  description: string | null
  /** Minor units (cents). Display only — Stripe is the source of truth. */
  monthly_amount: number
  annual_amount: number
  currency: string
  trial_days: number
  features: string[]
  /** null means unlimited — distinct from 0, which would mean none allowed. */
  limits: {
    users: number | null
    customers: number | null
    storage_mb: number | null
  }
  is_current: boolean
}

export interface Subscription {
  active: boolean
  status: string | null
  plan: Plan | null
  interval: BillingInterval | null
  on_trial: boolean
  trial_ends_at: string | null
  on_grace_period: boolean
  cancelled: boolean
  ends_at: string | null
  renews_at: string | null
}

export interface UsageMetric {
  used: number
  limit: number | null
  remaining: number | null
  exceeded: boolean
}

export interface Usage {
  users: UsageMetric
  customers: UsageMetric
  storage_mb: UsageMetric
}

/**
 * A Stripe invoice for the organization's own SaaS subscription.
 *
 * Named for its source to keep it apart from `Invoice`, which is an invoice the
 * organization issues to one of ITS customers. Different party, different money.
 */
export interface StripeInvoice {
  id: string
  number: string | null
  date: string
  total: string
  subtotal: string
  tax: string | null
  status: string
  paid: boolean
  download_url: string
}

export interface PaymentMethod {
  brand: string | null
  last_four: string | null
  exp_month: number | null
  exp_year: number | null
}

export interface BillingOverview {
  subscription: Subscription
  usage: Usage
  payment_method: PaymentMethod | null
}

// ─── Dashboard ───

export interface DashboardStats {
  customers: {
    total: number
    by_status: Record<string, number>
    new_this_month: number
  }
  revenue: {
    lifetime_value: number
    currency: string
  }
  growth: Array<{ month: string; label: string; count: number }>
  organization: {
    name: string
    status: string
    on_trial: boolean
    trial_ends_at: string | null
  }
  recent_customers: Customer[]
}

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
}
