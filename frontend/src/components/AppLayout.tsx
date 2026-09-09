import { Outlet } from 'react-router-dom'
import { useAuth } from '@/auth/AuthContext'
import { Button } from '@/components/ui/button'

export function AppLayout() {
  const { user, logout } = useAuth()

  return (
    <div className="min-h-screen flex flex-col">
      <header className="border-b bg-card">
        <div className="flex items-center justify-between px-4 h-14">
          <span className="font-semibold tracking-tight">STOCKWISE</span>
          <div className="flex items-center gap-3 text-sm">
            <span className="text-muted-foreground">
              {user?.name} · {user?.roles.join(', ')}
              {user?.site ? ` · ${user.site.code}` : ''}
            </span>
            <Button variant="outline" size="sm" onClick={() => logout()}>
              Keluar
            </Button>
          </div>
        </div>
      </header>
      <main className="flex-1 p-6">
        <Outlet />
      </main>
    </div>
  )
}
