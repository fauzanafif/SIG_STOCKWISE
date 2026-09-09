import { useAuth } from '@/auth/AuthContext'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

export function DashboardPage() {
  const { user } = useAuth()
  if (!user) return null

  return (
    <div className="space-y-4 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle>Selamat datang, {user.name}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <p className="text-muted-foreground">
            Dashboard per role menyusul di PHASE 9. Ini halaman sementara untuk memverifikasi auth &amp; RBAC.
          </p>
          <dl className="grid grid-cols-[8rem_1fr] gap-y-1">
            <dt className="text-muted-foreground">Username</dt>
            <dd>{user.username}</dd>
            <dt className="text-muted-foreground">Role</dt>
            <dd>{user.roles.join(', ')}</dd>
            <dt className="text-muted-foreground">Site</dt>
            <dd>{user.site ? `${user.site.name} (${user.site.code})` : '—'}</dd>
            <dt className="text-muted-foreground">Permission</dt>
            <dd>{user.permissions.length} permission aktif</dd>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Permission Anda</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="flex flex-wrap gap-1.5">
            {user.permissions.map((p) => (
              <li
                key={p}
                className="rounded bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
              >
                {p}
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  )
}
