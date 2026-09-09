import { useState } from 'react'
import { Link } from 'react-router-dom'
import { usePoList, type PurchaseOrder } from '@/features/purchasing/api'
import { ShoppingCart } from 'lucide-react'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'

const rupiah = (n: number) => 'Rp ' + Number(n).toLocaleString('id-ID')

const columns: Column<PurchaseOrder>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/purchase-orders/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor_name ?? '—' },
  { key: 'items', header: 'Item', cell: (r) => r.items_count ?? '—' },
  { key: 'total', header: 'Total', cell: (r) => rupiah(r.total) },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.date ? new Date(r.date).toLocaleDateString('id-ID') : '—') },
]

export function PoListPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = usePoList({ search: search || undefined, status: status || undefined, page })

  return (
    <div className="space-y-5">
      <PageHeader title="Purchase Order" subtitle="Pesanan pembelian ke vendor" icon={<ShoppingCart className="size-5" />} />
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
          {['DRAFT', 'APPROVED', 'SENT', 'PARTIAL_RECEIVED', 'RECEIVED', 'CANCELLED'].map((s) => (
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
