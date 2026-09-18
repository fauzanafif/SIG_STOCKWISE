import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useOpnames } from '@/features/opname/api'
import { useTriggerSync } from '@/features/sync/api'
import { PackageCheck, RefreshCw } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { ScheduleOpnameModal } from '@/components/ScheduleOpnameModal'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Opname } from '@/features/opname/api'

const columns: Column<Opname>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/stock-opnames/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'wh', header: 'Gudang', cell: (r) => r.warehouse?.code ?? '—' },
  { key: 'type', header: 'Tipe', cell: (r) => (r.type === 'PARTIAL' ? 'CUSTOM' : r.type) },
  { key: 'prog', header: 'Progress', cell: (r) => `${r.counted_count ?? 0}/${r.items_count ?? 0}` },
  { key: 'diff', header: 'Selisih', cell: (r) => r.diff_count ?? 0 },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.scheduled_date ? new Date(r.scheduled_date).toLocaleDateString('id-ID') : '—') },
  {
    key: 'sumber',
    header: 'Sumber',
    cell: (r) => (r.accurate_itemadj_id ? <Badge variant="default">Accurate</Badge> : <Badge variant="neutral">Internal</Badge>),
  },
]

export function OpnameListPage() {
  const { hasPermission } = useAuth()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useOpnames({ status: status || undefined, page })
  const [customOpen, setCustomOpen] = useState(false)

  const trigger = useTriggerSync()
  const canTrigger = hasPermission('sync.accurate.trigger')

  return (
    <div className="space-y-5">
      <PageHeader
        title="Stock Opname"
        subtitle="Hitung fisik & penyesuaian stok — internal & mirror ITEMADJ/ITADJDET dari Accurate"
        icon={<PackageCheck className="size-5" />}
        actions={
          <div className="flex gap-2">
            {canTrigger && (
              <Button size="sm" variant="outline" disabled={trigger.isPending} onClick={() => trigger.mutate()}>
                <RefreshCw className={`mr-2 size-4 ${trigger.isPending ? 'animate-spin' : ''}`} />
                {trigger.isPending ? 'Syncing…' : 'Sync Accurate'}
              </Button>
            )}
            {hasPermission('opname.schedule') && (
              <Button size="sm" onClick={() => setCustomOpen(true)}>ADD JADWAL</Button>
            )}
          </div>
        }
      />
      {trigger.isError && <p className="text-sm text-destructive">{apiErrorMessage(trigger.error)}</p>}
      <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={status}
        onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
        <option value="">Semua status</option>
        {['SCHEDULED', 'IN_PROGRESS', 'PENDING_REVIEW', 'COMPLETED', 'RECOUNT_REQUIRED'].map((s) => (
          <option key={s} value={s}>{s}</option>
        ))}
      </select>
      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} />
      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}

      <ScheduleOpnameModal open={customOpen} onClose={() => setCustomOpen(false)} onScheduled={() => setCustomOpen(false)} />
    </div>
  )
}
