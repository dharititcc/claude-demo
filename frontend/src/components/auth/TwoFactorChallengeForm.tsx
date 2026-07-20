import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ShieldCheck } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useTwoFactorChallenge } from '@/hooks/useAuth'

const codeSchema = z.object({
  code: z
    .string()
    .min(1, 'Enter the code from your authenticator.')
    // Accept the spaced "123 456" form authenticators display, since that is
    // what people copy; the API strips whitespace too.
    .refine((v) => /^\d{6}$/.test(v.replace(/\s+/g, '')), 'Enter the 6-digit code.'),
})

const recoverySchema = z.object({
  code: z.string().min(1, 'Enter one of your recovery codes.'),
})

type FormValues = { code: string }

interface Props {
  challengeToken: string
  onCancel: () => void
}

/**
 * The second step of a 2FA sign-in.
 *
 * The challenge survives a wrong code (five are allowed before it is torn up),
 * so a typo is recoverable here rather than throwing the user back to the
 * password screen.
 */
export default function TwoFactorChallengeForm({ challengeToken, onCancel }: Props) {
  const [useRecovery, setUseRecovery] = useState(false)
  const challenge = useTwoFactorChallenge()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(useRecovery ? recoverySchema : codeSchema),
  })

  const submit = handleSubmit(({ code }) =>
    challenge.mutate({
      challengeToken,
      ...(useRecovery ? { recoveryCode: code.trim() } : { code: code.replace(/\s+/g, '') }),
    }),
  )

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <ShieldCheck size={24} />
          </div>
          <h1 className="text-2xl font-semibold">Two-factor authentication</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {useRecovery
              ? 'Enter one of your recovery codes. Each one works once.'
              : 'Enter the 6-digit code from your authenticator app.'}
          </p>
        </div>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <div>
            <label htmlFor="code" className="mb-1.5 block text-sm font-medium">
              {useRecovery ? 'Recovery code' : 'Authentication code'}
            </label>
            <Input
              id="code"
              autoFocus
              autoComplete="one-time-code"
              // Numeric keypad on mobile for TOTP; recovery codes are alphanumeric.
              inputMode={useRecovery ? 'text' : 'numeric'}
              placeholder={useRecovery ? 'ABCDE-FGHIJ' : '123456'}
              error={errors.code?.message}
              {...register('code')}
            />
          </div>

          <Button type="submit" className="w-full" loading={challenge.isPending}>
            Verify
          </Button>
        </form>

        <div className="mt-6 flex flex-col items-center gap-2 text-sm">
          <button
            type="button"
            className="font-medium text-primary hover:underline"
            onClick={() => {
              setUseRecovery((v) => !v)
              reset({ code: '' })
            }}
          >
            {useRecovery ? 'Use your authenticator instead' : 'Lost your device? Use a recovery code'}
          </button>
          <button
            type="button"
            className="text-muted-foreground hover:underline"
            onClick={onCancel}
          >
            Back to sign in
          </button>
        </div>
      </div>
    </div>
  )
}
