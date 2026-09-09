import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useReceivingList, type Receiving } from '@/features/purchasing/api'
import { Truck } from 'lucide-react'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'

const columns: Column<Receiving>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/receivings/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'po', header: 'PO', cell: (r) => r.po_number ?? '—' },
  { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor_name ?? '—' },
  { key: 'wh', header: 'Gudang', cell: (r) => r.warehouse ?? '—' },
  { key: 'items', header: 'Item', cell: (r) => r.items_count ?? '—' },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.date ? new Date(r.date).toLocaleDateString('id-ID') : '—') },
]

export function ReceivingListPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useReceivingList({ search: search || undefined, status: status || undefined, page })

  return (
    <div className="space-y-5">
      <PageHeader title="Penerimaan Barang (RI)" subtitle="Barang masuk — stok bertambah saat dikonfirmasi" icon={<Truck className="size-5" />} />
      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari nomor…"
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
          {['CHECKING', 'CONFIRMED', 'REJECTED'].map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </div>
      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} />
      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}
    </div>
  )
}
