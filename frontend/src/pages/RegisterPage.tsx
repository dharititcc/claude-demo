import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link } from 'react-router-dom'
import { Building2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useRegister } from '@/hooks/useAuth'

/**
 * Mirrors the server's password policy (App\Providers\AppServiceProvider) so the
 * user gets immediate feedback. The server remains the authority — this is
 * convenience, not enforcement.
 */
const schema = z
  .object({
    name: z.string().min(1, 'Your name is required.').max(255),
    organization_name: z.string().min(1, 'Give your organization a name.').max(255),
    email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
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

export default function RegisterPage() {
  const registerMutation = useRegister()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Building2 size={24} />
          </div>
          <h1 className="text-2xl font-semibold">Create your organization</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Start a 14-day trial. No card required.
          </p>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form
          onSubmit={handleSubmit((values) => registerMutation.mutate(values))}
          className="space-y-4"
          noValidate
        >
          <div>
            <label htmlFor="name" className="mb-1.5 block text-sm font-medium">
              Your name
            </label>
            <Input id="name" autoFocus error={errors.name?.message} {...register('name')} />
          </div>

          <div>
            <label htmlFor="organization_name" className="mb-1.5 block text-sm font-medium">
              Organization
            </label>
            <Input
              id="organization_name"
              placeholder="Acme Inc"
              error={errors.organization_name?.message}
              {...register('organization_name')}
            />
          </div>

          <div>
            <label htmlFor="email" className="mb-1.5 block text-sm font-medium">
              Work email
            </label>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              error={errors.email?.message}
              {...register('email')}
            />
          </div>

          <div>
            <label htmlFor="password" className="mb-1.5 block text-sm font-medium">
              Password
            </label>
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
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
              error={errors.password_confirmation?.message}
              {...register('password_confirmation')}
            />
          </div>

          <Button type="submit" className="w-full" loading={registerMutation.isPending}>
            Create organization
          </Button>
        </form>

        <p className="mt-6 text-center text-sm text-muted-foreground">
          Already have an account?{' '}
          <Link to="/login" className="font-medium text-primary hover:underline">
            Sign in
          </Link>
        </p>
      </div>
    </div>
  )
}
