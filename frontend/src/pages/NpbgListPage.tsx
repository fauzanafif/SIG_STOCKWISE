import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ScrollText } from 'lucide-react'
import { useNpbgList } from '@/features/npbg/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Npbg } from '@/features/npbg/api'

const columns: Column<Npbg>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/npbg/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'klas', header: 'Klasifikasi', cell: (r) => r.classification },
  { key: 'req', header: 'Peminta', cell: (r) => r.requester ?? '—' },
  { key: 'wh', header: 'Gudang', cell: (r) => r.warehouse?.code ?? '—' },
  { key: 'items', header: 'Item', cell: (r) => r.items_count ?? '—' },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.date ? new Date(r.date).toLocaleDateString('id-ID') : '—') },
]

export function NpbgListPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useNpbgList({ search: search || undefined, status: status || undefined, page })

  return (
    <div className="space-y-4">
      <PageHeader title="NPBG" subtitle="Nota Pengeluaran Barang Gudang" icon={<ScrollText className="size-5" />} />
      <div className="flex flex-wrap gap-2">
        <Input placeholder="Cari nomor…" className="max-w-xs" value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <select className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Semua status</option>
          {['PREPARING', 'READY_TO_PICKUP', 'PICKED_UP', 'COMPLETED', 'CANCELLED'].map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
      </div>
      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} />
      {data && (
        <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />
      )}
    </div>
  )
}
