import { useState } from 'react'
import { useItems } from '@/features/inventory/api'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import type { Item } from '@/types/inventory'

const columns: Column<Item>[] = [
  { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.code}</span> },
  { key: 'description', header: 'Deskripsi', cell: (r) => r.description },
  { key: 'category', header: 'Kategori', cell: (r) => r.category?.name ?? '—' },
  { key: 'unit', header: 'UoM', cell: (r) => r.unit?.code ?? '—' },
  { key: 'lt', header: 'Lead Time', cell: (r) => (r.lead_time_days != null ? `${r.lead_time_days} hr` : '—') },
  {
    key: 'available',
    header: 'Tersedia',
    cell: (r) =>
      r.analysis
        ? r.analysis.stock_known
          ? r.analysis.available
          : <span className="text-muted-foreground">UNKNOWN</span>
        : '—',
  },
  { key: 'ss', header: 'Safety', cell: (r) => r.analysis?.safety_stock ?? r.safety_stock ?? '—' },
  {
    key: 'status',
    header: 'Status',
    cell: (r) => (r.analysis ? <StatusBadge status={r.analysis.status} /> : '—'),
  },
  {
    key: 'priority',
    header: 'Prioritas',
    cell: (r) => (r.analysis ? <PriorityBadge level={r.analysis.priority_level} /> : '—'),
  },
]

export function ItemsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError } = useItems({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 25,
  })

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold">Master Barang</h1>
        <p className="text-sm text-muted-foreground">
          {data ? `${data.meta.total} barang` : 'Memuat…'} — data dari DATA.xlsx
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari kode / deskripsi…"
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
          <option value="AMAN">Aman</option>
          <option value="TIDAK_AMAN">Tidak Aman</option>
          <option value="BEP">BEP</option>
        </select>
      </div>

      {isError && <p className="text-sm text-destructive">Gagal memuat data.</p>}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(r) => r.id}
        isLoading={isLoading}
      />

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
