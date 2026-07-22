import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Building2 } from 'lucide-react'
import { teamService } from '@/services/team'
import { authService } from '@/services/auth'
import { getToken } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate } from '@/lib/date'
import { usePageTitle } from '@/hooks/usePageTitle'

/**
 * Landing page for an emailed invitation link.
 *
 * Deliberately reachable while signed out: the recipient needs to see which
 * organization invited them, and to which address, before deciding to sign in or
 * register. The token grants nothing on its own — accepting requires being
 * authenticated as the invited address.
 */
export default function AcceptInvitationPage() {
  usePageTitle('Accept invitation')

  const { token = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const setSession = useAuthStore((s) => s.setSession)
  const setActiveOrg = useAuthStore((s) => s.setActiveOrg)
  const user = useAuthStore((s) => s.user)
  const signedIn = Boolean(getToken())

  const preview = useQuery({
    queryKey: ['invitation', token],
    queryFn: () => teamService.previewInvitation(token),
    retry: false,
  })

  const accept = useMutation({
    mutationFn: () => teamService.acceptInvitation(token),
    onSuccess: async (organization) => {
      // The user now belongs to one more organization; refresh the session so
      // the switcher lists it, then drop them straight into it.
      const { user } = await authService.me()
      setSession(user, user.organizations ?? [])
      setActiveOrg(organization.slug)
      queryClient.clear()
      toast.success(`You've joined ${organization.name}.`)
      navigate('/dashboard', { replace: true })
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not accept the invitation.')),
  })

  if (preview.isLoading) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (preview.isError || !preview.data) {
    return (
      <div className="flex min-h-svh flex-col items-center justify-center gap-4 p-8 text-center">
        <h1 className="text-xl font-semibold">This invitation isn't valid</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          It may have expired, already been used, or been revoked. Ask whoever invited you to send
          a new one.
        </p>
        <Button onClick={() => navigate('/login')}>Go to sign in</Button>
      </div>
    )
  }

  const invitation = preview.data
  // Accepting is bound to the invited address — signing in as someone else won't work.
  const wrongAccount = signedIn && user && user.email.toLowerCase() !== invitation.email.toLowerCase()

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
          <Building2 size={24} />
        </div>

        <h1 className="text-2xl font-semibold">Join {invitation.organization_name}</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {invitation.invited_by ? `${invitation.invited_by} invited` : 'You were invited'}{' '}
          <span className="font-medium text-foreground">{invitation.email}</span> to join as
        </p>
        <Badge className="mt-2">{invitation.role}</Badge>

        <div className="mt-8">
          {!signedIn ? (
            <div className="space-y-3">
              <p className="text-sm text-muted-foreground">
                Sign in as {invitation.email} to accept.
              </p>
              <Button className="w-full" onClick={() => navigate('/login', { state: { from: `/invitations/${token}` } })}>
                Sign in
              </Button>
              <Link
                to="/register"
                className="block text-sm text-primary hover:underline"
              >
                Don't have an account? Create one
              </Link>
            </div>
          ) : wrongAccount ? (
            <div className="space-y-3">
              <p className="text-sm text-destructive">
                You're signed in as {user?.email}, but this invitation is for {invitation.email}.
              </p>
              <Button variant="outline" className="w-full" onClick={() => navigate('/login')}>
                Sign in with a different account
              </Button>
            </div>
          ) : (
            <Button className="w-full" loading={accept.isPending} onClick={() => accept.mutate()}>
              Accept invitation
            </Button>
          )}
        </div>

        <p className="mt-6 text-xs text-muted-foreground">
          Expires {formatDate(invitation.expires_at)}
        </p>
      </div>
    </div>
  )
}
