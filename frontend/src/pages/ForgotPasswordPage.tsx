import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MailCheck, Building2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { authService } from '@/services/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { usePageTitle } from '@/hooks/usePageTitle'

const schema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
})

type FormValues = z.infer<typeof schema>

export default function ForgotPasswordPage() {
  usePageTitle('Reset your password')

  // The confirmation replaces the form rather than sitting beside it: leaving a
  // submittable form on screen invites repeat submissions against a 6/min limit.
  const [sentTo, setSentTo] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  const request = useMutation({
    mutationFn: (values: FormValues) => authService.forgotPassword(values.email),
    onSuccess: (_message, values) => setSentTo(values.email),
  })

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            {sentTo ? <MailCheck size={24} /> : <Building2 size={24} />}
          </div>
          <h1 className="text-2xl font-semibold">
            {sentTo ? 'Check your email' : 'Forgot your password?'}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {sentTo
              ? // Deliberately not "we sent you an email": the API answers the
                // same way whether or not the address is registered, and saying
                // more here would leak what it withholds.
                'If that address is registered, a reset link is on its way. The link expires in 60 minutes.'
              : 'Enter your email and we will send you a link to set a new one.'}
          </p>
        </div>

        {sentTo ? (
          <div className="space-y-4">
            <p className="rounded-md border bg-muted/40 p-3 text-center text-sm">{sentTo}</p>
            <Button variant="outline" className="w-full" onClick={() => setSentTo(null)}>
              Use a different address
            </Button>
          </div>
        ) : (
          /* noValidate: our Zod errors are authoritative, not the browser's. */
          <form
            onSubmit={handleSubmit((values) => request.mutate(values))}
            className="space-y-4"
            noValidate
          >
            <div>
              <label htmlFor="email" className="mb-1.5 block text-sm font-medium">
                Email
              </label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                autoFocus
                placeholder="you@company.com"
                error={errors.email?.message}
                {...register('email')}
              />
            </div>

            {request.isError && (
              <p className="text-sm text-destructive">
                {apiErrorMessage(request.error, 'Could not send the reset link.')}
              </p>
            )}

            <Button type="submit" className="w-full" loading={request.isPending}>
              Send reset link
            </Button>
          </form>
        )}

        <p className="mt-6 text-center text-sm text-muted-foreground">
          Remembered it?{' '}
          <Link to="/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        </p>
      </div>
    </div>
  )
}
