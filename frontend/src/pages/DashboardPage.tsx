import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { CloudCog, LayoutDashboard, TrendingUp } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { useDashboard, type DashboardCard } from '@/features/dashboard/api'
import { useSyncStatus } from '@/features/sync/api'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import { cn } from '@/lib/utils'

function AccurateSyncWidget() {
  const { hasPermission } = useAuth()
  const { data: sync } = useSyncStatus()
  if (!hasPermission('sync.accurate.view')) return null

  const badgeVariant =
    sync?.status === 'SUCCESS' ? 'success' : sync?.status === 'FAILED' ? 'danger' : sync?.status === 'PARTIAL' ? 'warning' : 'neutral'

  return (
    <Link to="/sync/accurate" className="card-surface flex items-center justify-between gap-4 p-4 transition hover:border-primary/40">
      <div className="flex items-center gap-3">
        <CloudCog className="size-5 text-muted-foreground" />
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Accurate Sync</div>
          <div className="text-sm">
            {sync ? `${sync.total_records} barang · ${new Date(sync.started_at).toLocaleString('id-ID')}` : 'Belum pernah sync'}
          </div>
        </div>
      </div>
      <Badge variant={badgeVariant}>{sync?.status ?? 'N/A'}</Badge>
    </Link>
  )
}

const TONE: Record<DashboardCard['tone'], string> = {
  default: 'text-foreground',
  success: 'text-emerald-600',
  warning: 'text-amber-600',
  danger: 'text-red-600',
}

const PIE_COLORS = ['#2563eb', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#64748b']

export function DashboardPage() {
  const { user } = useAuth()
  const { data, isLoading } = useDashboard()
  if (!user) return null

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Selamat datang, ${user.name.split(' ')[0]}`}
        subtitle={`${user.roles.join(', ')}${user.site ? ` · ${user.site.name}` : ''}`}
        icon={<LayoutDashboard className="size-5" />}
      />

      <AccurateSyncWidget />

      {isLoading && <p className="text-muted-foreground">Memuat ringkasan…</p>}

      {data && data.cards.length > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {data.cards.map((c) => (
            <div key={c.key} className="card-surface p-4">
              <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{c.label}</div>
              <div className={cn('mt-1 text-2xl font-semibold tabular-nums', TONE[c.tone])}>{c.value}</div>
            </div>
          ))}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {data?.charts.request_status && data.charts.request_status.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Status Request</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={240}>
                <PieChart>
                  <Pie
                    data={data.charts.request_status}
                    dataKey="value"
                    nameKey="name"
                    innerRadius={50}
                    outerRadius={85}
                    paddingAngle={2}
                  >
                    {data.charts.request_status.map((_, i) => (
                      <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        )}

        {data?.charts.stock_movement_14d && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <TrendingUp className="size-4" /> Pergerakan Stok 14 Hari
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={240}>
                <BarChart data={data.charts.stock_movement_14d}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="name" tick={{ fontSize: 10 }} tickFormatter={(v) => String(v).slice(5)} />
                  <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="in" name="Masuk" fill="#22c55e" radius={[3, 3, 0, 0]} />
                  <Bar dataKey="out" name="Keluar" fill="#ef4444" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        )}
      </div>

      {data?.lists.recent_requests && data.lists.recent_requests.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Request Terbaru</CardTitle>
          </CardHeader>
          <CardContent className="divide-y">
            {data.lists.recent_requests.map((r) => (
              <Link
                key={r.id}
                to={`/requests/${r.id}`}
                className="flex items-center justify-between py-2 text-sm hover:text-primary"
              >
                <span className="font-mono text-xs">{r.number}</span>
                <span className="flex items-center gap-3">
                  <RequestStatusBadge status={r.status} />
                  <span className="text-muted-foreground">{r.date}</span>
                </span>
              </Link>
            ))}
          </CardContent>
        </Card>
      )}

      {data && data.cards.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Belum ada ringkasan untuk peran Anda. Gunakan menu di samping untuk mulai bekerja.
          </CardContent>
        </Card>
      )}
    </div>
  )
}
