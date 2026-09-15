import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ClipboardList, Plus } from 'lucide-react'
import { useRequests } from '@/features/requests/api'
import { useAuth } from '@/auth/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { MaterialRequest } from '@/types/request'

const fmtDate = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID') : '—')

const columns: Column<MaterialRequest>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/requests/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'date', header: 'Tanggal', cell: (r) => fmtDate(r.request_date ?? r.created_at) },
  {
    key: 'requester',
    header: 'Peminta',
    cell: (r) => (
      <span className="inline-flex items-center gap-1.5">
        {r.requester_name ?? r.requester.name ?? '—'}
        {r.network_label === 'EXTERNAL' && (
          <Badge variant="warning" title="Request dikirim dari luar jaringan kantor">
            luar kantor
          </Badge>
        )}
      </span>
    ),
  },
  { key: 'purpose', header: 'Keterangan', cell: (r) => r.purpose },
  { key: 'items', header: 'Barang', cell: (r) => r.items_count ?? '—' },
  {
    key: 'refs',
    header: 'Bukti Keluar / PPB',
    cell: (r) => (
      <span className="font-mono text-xs text-muted-foreground">
        {r.npbg_no ?? '—'} / {r.ppb_no ?? '—'}
      </span>
    ),
  },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

export function RequestListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useRequests({
    search: search || undefined,
    status: status || undefined,
    page,
  })

  return (
    <div className="space-y-5">
      <PageHeader
        title="Request Barang"
        subtitle={hasPermission('request.view') ? 'Semua request' : 'Request saya'}
        icon={<ClipboardList className="size-5" />}
        actions={
          hasPermission('request.create') ? (
            <Button asChild size="sm">
              <Link to="/requests/new">
                <Plus className="size-4" />
                Buat Request
              </Link>
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari nomor / keperluan…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua status</option>
          {['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RESERVED', 'PARTIAL', 'NEED_PURCHASE', 'COMPLETED', 'CANCELLED'].map(
            (s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ),
          )}
        </select>
      </div>

      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} />

      {data && (
        <Pagination
          page={data.meta.page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPage={setPage}
        />
      )}
    </div>
  )
}
