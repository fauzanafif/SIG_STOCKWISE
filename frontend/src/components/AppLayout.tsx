import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '@/auth/AuthContext'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface NavItem {
  to: string
  label: string
  permission?: string
}

const NAV: NavItem[] = [
  { to: '/', label: 'Dashboard' },
  { to: '/items', label: 'Master Barang', permission: 'item.view' },
  { to: '/inventory/analysis', label: 'Analisis Inventory', permission: 'inventory.view_analysis' },
]

export function AppLayout() {
  const { user, logout, hasPermission } = useAuth()
  const items = NAV.filter((n) => !n.permission || hasPermission(n.permission))

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
      <div className="flex flex-1">
        <nav className="w-48 shrink-0 border-r bg-card/50 p-3">
          <ul className="space-y-1">
            {items.map((n) => (
              <li key={n.to}>
                <NavLink
                  to={n.to}
                  end={n.to === '/'}
                  className={({ isActive }) =>
                    cn(
                      'block rounded px-3 py-2 text-sm',
                      isActive ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                    )
                  }
                >
                  {n.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <main className="flex-1 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
