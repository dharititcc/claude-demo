import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { QRCodeSVG } from 'qrcode.react'
import toast from 'react-hot-toast'
import { ShieldCheck, ShieldOff } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card'
import { authService } from '@/services/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { formatDate } from '@/lib/date'

/**
 * The one moment the full recovery-code list is worth showing.
 *
 * They are the only way back in when the authenticator is gone, so they are
 * presented for copying rather than buried behind another click.
 */
function RecoveryCodeList({ codes, onDone }: { codes: string[]; onDone?: () => void }) {
  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        Store these somewhere safe. Each code works once, and they are the only way into your
        account if you lose your authenticator.
      </p>
      <ul className="grid grid-cols-2 gap-2 rounded-lg border bg-muted/40 p-4 font-mono text-sm">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <div className="flex gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            void navigator.clipboard.writeText(codes.join('\n'))
            toast.success('Recovery codes copied.')
          }}
        >
          Copy codes
        </Button>
        {onDone && (
          <Button type="button" onClick={onDone}>
            Done
          </Button>
        )}
      </div>
    </div>
  )
}

export default function TwoFactorSettings() {
  const queryClient = useQueryClient()

  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [showDisable, setShowDisable] = useState(false)
  // Held in component state, never refetched: the API returns these once at
  // confirmation and there is nowhere else to read them from afterwards.
  const [freshCodes, setFreshCodes] = useState<string[] | null>(null)

  const status = useQuery({
    queryKey: ['two-factor'],
    queryFn: () => authService.twoFactorStatus(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['two-factor'] })

  const enable = useMutation({
    mutationFn: () => authService.enableTwoFactor(),
    onSuccess: invalidate,
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not start enrolment.')),
  })

  const confirm = useMutation({
    mutationFn: () => authService.confirmTwoFactor(code),
    onSuccess: (codes) => {
      setFreshCodes(codes)
      setCode('')
      toast.success('Two-factor authentication is on.')
      void invalidate()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'That code was not accepted.')),
  })

  const disable = useMutation({
    mutationFn: () => authService.disableTwoFactor(password),
    onSuccess: () => {
      setPassword('')
      setShowDisable(false)
      setFreshCodes(null)
      toast.success('Two-factor authentication is off.')
      void invalidate()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not disable two-factor.')),
  })

  const regenerate = useMutation({
    mutationFn: () => authService.regenerateRecoveryCodes(password),
    onSuccess: (codes) => {
      setPassword('')
      setFreshCodes(codes)
      toast.success('New recovery codes issued. The old ones no longer work.')
      void invalidate()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not regenerate codes.')),
  })

  const enrolment = enable.data

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {status.data?.enabled ? <ShieldCheck size={18} /> : <ShieldOff size={18} />}
          Two-factor authentication
        </CardTitle>
        <CardDescription>
          Require a code from your authenticator app in addition to your password.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {status.isPending && <p className="text-sm text-muted-foreground">Loading…</p>}

        {/* Freshly issued codes take over the card — they cannot be recovered
            from anywhere else once dismissed. */}
        {freshCodes && <RecoveryCodeList codes={freshCodes} onDone={() => setFreshCodes(null)} />}

        {/* ── Off, and not yet enrolling ── */}
        {!freshCodes && status.data && !status.data.enabled && !enrolment && (
          <Button type="button" onClick={() => enable.mutate()} loading={enable.isPending}>
            Enable two-factor
          </Button>
        )}

        {/* ── Enrolling: scan, then confirm ── */}
        {!freshCodes && status.data && !status.data.enabled && enrolment && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Scan this with your authenticator, then enter the code it shows. Your account is not
              protected until you do.
            </p>

            <div className="flex justify-center rounded-lg border bg-white p-4">
              <QRCodeSVG value={enrolment.otpauth_uri} size={176} />
            </div>

            <div>
              <p className="mb-1.5 text-sm font-medium">Can&apos;t scan it?</p>
              <p className="break-all rounded-md bg-muted/40 p-2 font-mono text-xs">
                {enrolment.secret}
              </p>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault()
                confirm.mutate()
              }}
              className="space-y-3"
              noValidate
            >
              <div>
                <label htmlFor="tfa-confirm" className="mb-1.5 block text-sm font-medium">
                  Authentication code
                </label>
                <Input
                  id="tfa-confirm"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                />
              </div>
              <Button type="submit" loading={confirm.isPending} disabled={!code.trim()}>
                Confirm and turn on
              </Button>
            </form>
          </div>
        )}

        {/* ── On ── */}
        {!freshCodes && status.data?.enabled && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Enabled{' '}
              {status.data.confirmed_at
                ? formatDate(status.data.confirmed_at)
                : ''}
              . {status.data.recovery_codes_remaining} recovery{' '}
              {status.data.recovery_codes_remaining === 1 ? 'code' : 'codes'} remaining.
            </p>

            {/* Running low is worth surfacing before it becomes a lockout. */}
            {status.data.recovery_codes_remaining <= 2 && (
              <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                You are nearly out of recovery codes. Generate a new set so you are not locked out
                if you lose your device.
              </p>
            )}

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={async () => {
                  try {
                    setFreshCodes(await authService.recoveryCodes())
                  } catch (e) {
                    toast.error(apiErrorMessage(e, 'Could not load recovery codes.'))
                  }
                }}
              >
                View recovery codes
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => setShowDisable((v) => !v)}
              >
                {showDisable ? 'Cancel' : 'Disable or regenerate'}
              </Button>
            </div>

            {/* Both actions re-check the password: a stolen token must not be
                enough to strip or reset the second factor. */}
            {showDisable && (
              <form
                onSubmit={(e) => e.preventDefault()}
                className="space-y-3 rounded-lg border p-4"
                noValidate
              >
                <div>
                  <label htmlFor="tfa-password" className="mb-1.5 block text-sm font-medium">
                    Confirm your password
                  </label>
                  <Input
                    id="tfa-password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    disabled={!password}
                    loading={regenerate.isPending}
                    onClick={() => regenerate.mutate()}
                  >
                    Regenerate recovery codes
                  </Button>
                  <Button
                    type="button"
                    variant="destructive"
                    disabled={!password}
                    loading={disable.isPending}
                    onClick={() => disable.mutate()}
                  >
                    Turn off two-factor
                  </Button>
                </div>
              </form>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
