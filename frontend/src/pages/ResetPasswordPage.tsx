import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Building2, TriangleAlert } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { authService } from '@/services/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { usePageTitle } from '@/hooks/usePageTitle'

/**
 * Mirrors the server's password policy so the user gets immediate feedback.
 * The server remains the authority — this is convenience, not enforcement.
 */
const schema = z
  .object({
    password: z
      .string()
      .min(12, 'Use at least 12 characters.')
      .regex(/[a-z]/, 'Include a lowercase letter.')
      .regex(/[A-Z]/, 'Include an uppercase letter.')
      .regex(/[0-9]/, 'Include a number.')
      .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type FormValues = z.infer<typeof schema>

export default function ResetPasswordPage() {
  usePageTitle('Set a new password')

  const navigate = useNavigate()
  const [params] = useSearchParams()

  // Both come from the emailed link (see AppServiceProvider::configurePasswordReset).
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  const reset = useMutation({
    mutationFn: (values: FormValues) =>
      authService.resetPassword({
        token,
        email,
        password: values.password,
        password_confirmation: values.password_confirmation,
      }),
    onSuccess: () => {
      // Every existing token was revoked server-side, so there is no session to
      // continue into — signing in again is the only way forward.
      toast.success('Password reset. Please sign in.')
      navigate('/login', { replace: true })
    },
  })

  // A link that lost its query string cannot work; say so instead of showing a
  // form that is guaranteed to fail on submit.
  if (!token || !email) {
    return (
      <div className="flex min-h-svh items-center justify-center p-4">
        <div className="w-full max-w-sm text-center">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-destructive/10 text-destructive">
            <TriangleAlert size={24} />
          </div>
          <h1 className="text-2xl font-semibold">This link is not valid</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            It looks incomplete. Reset links can also be truncated by some email clients — request a
            fresh one.
          </p>
          <Button className="mt-6 w-full" onClick={() => navigate('/forgot-password')}>
            Request a new link
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Building2 size={24} />
          </div>
          <h1 className="text-2xl font-semibold">Set a new password</h1>
          <p className="mt-1 text-sm text-muted-foreground">for {email}</p>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form
          onSubmit={handleSubmit((values) => reset.mutate(values))}
          className="space-y-4"
          noValidate
        >
          <div>
            <label htmlFor="password" className="mb-1.5 block text-sm font-medium">
              New password
            </label>
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
              autoFocus
              placeholder="••••••••••••"
              error={errors.password?.message}
              {...register('password')}
            />
          </div>

          <div>
            <label htmlFor="password_confirmation" className="mb-1.5 block text-sm font-medium">
              Confirm password
            </label>
            <Input
              id="password_confirmation"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••••••"
              error={errors.password_confirmation?.message}
              {...register('password_confirmation')}
            />
          </div>

          {reset.isError && (
            <p className="text-sm text-destructive">
              {/* Covers the expired/already-used token case, which the API
                  reports against `email`. */}
              {apiErrorMessage(reset.error, 'Could not reset your password. The link may have expired.')}
            </p>
          )}

          <Button type="submit" className="w-full" loading={reset.isPending}>
            Reset password
          </Button>
        </form>

        <p className="mt-6 text-center text-sm text-muted-foreground">
          <Link to="/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        </p>
      </div>
    </div>
  )
}
