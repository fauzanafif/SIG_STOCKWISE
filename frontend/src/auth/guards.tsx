import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from './AuthContext'

export function RequireAuth() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />

  return <Outlet />
}

export function RequirePermission({ permission }: { permission: string }) {
  const { hasPermission } = useAuth()

  if (!hasPermission(permission)) {
    return (
      <div className="p-8 text-center text-muted-foreground">
        Anda tidak punya akses ke halaman ini ({permission}).
      </div>
    )
  }
  return <Outlet />
}

function FullScreenLoader() {
  return (
    <div className="min-h-screen grid place-items-center text-muted-foreground">
      Memuat…
    </div>
  )
}
