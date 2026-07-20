import { Badge } from '@/components/ui/Badge'
import type { AdminOrganization } from '@/types/admin'

/** Map an organization's lifecycle state to a badge, including derived states. */
export function OrgStatusBadge({
  org,
}: {
  org: Pick<AdminOrganization, 'status' | 'is_trial_expired' | 'deleted_at'>
}) {
  if (org.deleted_at) return <Badge variant="muted">Deleted</Badge>
  if (org.is_trial_expired) return <Badge variant="danger">Trial expired</Badge>

  switch (org.status) {
    case 'active':
      return <Badge variant="success">Active</Badge>
    case 'trial':
      return <Badge variant="warning">Trial</Badge>
    case 'suspended':
      return <Badge variant="danger">Suspended</Badge>
    case 'cancelled':
      return <Badge variant="muted">Cancelled</Badge>
    default:
      return <Badge variant="muted">{org.status}</Badge>
  }
}
