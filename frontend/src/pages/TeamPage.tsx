import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import toast from 'react-hot-toast'
import { Mail, Trash2, UserPlus, X } from 'lucide-react'
import { teamService } from '@/services/team'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card, CardHeader, CardTitle } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate } from '@/lib/date'
import type { Role } from '@/types'

const ROLES: Role[] = ['owner', 'admin', 'manager', 'employee', 'viewer']

const inviteSchema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
  role: z.enum(['owner', 'admin', 'manager', 'employee', 'viewer']),
})

type InviteValues = z.infer<typeof inviteSchema>

export default function TeamPage() {
  const [inviting, setInviting] = useState(false)
  const queryClient = useQueryClient()
  const can = useAuthStore((s) => s.can)
  const currentUser = useAuthStore((s) => s.user)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  const members = useQuery({
    queryKey: ['members', orgSlug],
    queryFn: teamService.members,
    enabled: Boolean(orgSlug),
  })

  const invitations = useQuery({
    queryKey: ['invitations', orgSlug],
    queryFn: teamService.invitations,
    enabled: Boolean(orgSlug && can('team.invite')),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['members', orgSlug] })
    queryClient.invalidateQueries({ queryKey: ['invitations', orgSlug] })
  }

  const invite = useMutation({
    mutationFn: teamService.invite,
    onSuccess: () => {
      toast.success('Invitation sent.')
      refresh()
      setInviting(false)
      reset()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not send the invitation.')),
  })

  const updateRole = useMutation({
    mutationFn: ({ id, role }: { id: number; role: Role }) => teamService.updateRole(id, role),
    onSuccess: () => {
      toast.success('Role updated.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not update the role.')),
  })

  const remove = useMutation({
    mutationFn: teamService.removeMember,
    onSuccess: () => {
      toast.success('Member removed.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not remove the member.')),
  })

  const revoke = useMutation({
    mutationFn: teamService.revokeInvitation,
    onSuccess: () => {
      toast.success('Invitation revoked.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not revoke the invitation.')),
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<InviteValues>({
    resolver: zodResolver(inviteSchema),
    defaultValues: { email: '', role: 'employee' },
  })

  const canManage = can('team.update') && can('team.remove')

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Team</h1>
          <p className="text-sm text-muted-foreground">
            People in this organization and the role they hold here.
          </p>
        </div>
        {can('team.invite') && !inviting && (
          <Button onClick={() => setInviting(true)}>
            <UserPlus size={16} />
            Invite member
          </Button>
        )}
      </div>

      {inviting && (
        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <CardTitle>Invite someone</CardTitle>
            <Button variant="ghost" size="icon" onClick={() => setInviting(false)} aria-label="Cancel">
              <X size={16} />
            </Button>
          </CardHeader>
          <form
            onSubmit={handleSubmit((v) => invite.mutate(v))}
            className="flex flex-wrap items-start gap-3 p-6 pt-0"
            noValidate
          >
            <div className="min-w-56 flex-1">
              <label htmlFor="invite-email" className="mb-1.5 block text-sm font-medium">
                Email
              </label>
              <Input
                id="invite-email"
                type="email"
                placeholder="teammate@company.com"
                error={errors.email?.message}
                {...register('email')}
              />
            </div>
            <div>
              <label htmlFor="invite-role" className="mb-1.5 block text-sm font-medium">
                Role
              </label>
              <select
                id="invite-role"
                className="h-10 rounded-md border bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                {...register('role')}
              >
                {ROLES.filter((r) => r !== 'owner' || can('billing.manage')).map((r) => (
                  <option key={r} value={r}>
                    {r}
                  </option>
                ))}
              </select>
            </div>
            <Button type="submit" className="mt-[26px]" loading={invite.isPending}>
              Send invite
            </Button>
          </form>
        </Card>
      )}

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/40 text-left">
              <tr>
                <th className="px-4 py-3 font-medium">Member</th>
                <th className="px-4 py-3 font-medium">Role</th>
                <th className="px-4 py-3 font-medium">Last active</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {members.isLoading ? (
                <tr>
                  <td colSpan={4} className="py-12 text-center">
                    <Spinner className="mx-auto h-5 w-5" />
                  </td>
                </tr>
              ) : (
                members.data?.map((member) => {
                  const isSelf = member.id === currentUser?.id
                  return (
                    <tr key={member.id} className="hover:bg-accent/50">
                      <td className="px-4 py-3">
                        <p className="font-medium">
                          {member.name}
                          {isSelf && <span className="ml-2 text-xs text-muted-foreground">(you)</span>}
                        </p>
                        <p className="text-xs text-muted-foreground">{member.email}</p>
                      </td>
                      <td className="px-4 py-3">
                        {canManage && !isSelf ? (
                          <select
                            value={member.role ?? ''}
                            onChange={(e) => updateRole.mutate({ id: member.id, role: e.target.value as Role })}
                            aria-label={`Role for ${member.name}`}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                          >
                            {ROLES.map((r) => (
                              <option key={r} value={r}>
                                {r}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <Badge variant={member.is_owner ? 'default' : 'muted'}>
                            {member.role ?? '—'}
                          </Badge>
                        )}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {member.last_login_at
                          ? formatDate(member.last_login_at)
                          : 'Never'}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {canManage && !isSelf && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove ${member.name}`}
                            onClick={() => {
                              if (window.confirm(`Remove ${member.name} from this organization?`)) {
                                remove.mutate(member.id)
                              }
                            }}
                          >
                            <Trash2 size={15} className="text-destructive" />
                          </Button>
                        )}
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {can('team.invite') && invitations.data && invitations.data.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Mail size={16} /> Pending invitations
            </CardTitle>
          </CardHeader>
          <ul className="divide-y">
            {invitations.data.map((invitation) => (
              <li key={invitation.id} className="flex items-center justify-between gap-3 px-6 py-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{invitation.email}</p>
                  <p className="text-xs text-muted-foreground">
                    {invitation.role} · expires {formatDate(invitation.expires_at)}
                  </p>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => revoke.mutate(invitation.id)}
                  loading={revoke.isPending}
                >
                  Revoke
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
