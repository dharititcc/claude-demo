import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Button } from '@/components/ui/Button'

interface Props {
  children: ReactNode
}

interface State {
  error: Error | null
}

/**
 * Catches render-time errors so one broken component doesn't blank the whole
 * app. Must be a class: React has no hook equivalent for componentDidCatch.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Replace with a real reporter (Sentry, etc.) in production.
    console.error('Unhandled UI error:', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children

    return (
      <div className="flex min-h-svh flex-col items-center justify-center gap-4 p-8 text-center">
        <h1 className="text-xl font-semibold">Something went wrong</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          The page failed to render. Reloading usually fixes it — if it keeps happening, please
          let us know.
        </p>
        {import.meta.env.DEV && (
          <pre className="max-w-xl overflow-x-auto rounded-md bg-muted p-3 text-left text-xs">
            {this.state.error.message}
          </pre>
        )}
        <Button onClick={() => window.location.reload()}>Reload page</Button>
      </div>
    )
  }
}
