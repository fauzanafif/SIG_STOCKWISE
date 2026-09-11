import { useState } from 'react'
import { Package, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeleteItem, useItems } from '@/features/inventory/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemFormModal } from '@/components/ItemFormModal'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Badge, PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { Item } from '@/types/inventory'

export function ItemsPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Item | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const del = useDeleteItem()

  const { data, isLoading, isError } = useItems({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 25,
  })

  const canEdit = hasPermission('item.update')
  const canDelete = hasPermission('item.delete')

  function openCreate() {
    setEditing(null)
    setFormOpen(true)
  }

  function openEdit(item: Item) {
    setEditing(item)
    setFormOpen(true)
  }

  function remove(item: Item) {
    if (!window.confirm(`Hapus barang ${item.code} — ${item.description}?`)) return
    setErr(null)
    del.mutate(item.id, { onError: (e) => setErr(apiErrorMessage(e)) })
  }

  const columns: Column<Item>[] = [
    { key: 'code', header: 'Kode Barang', cell: (r) => <span className="font-mono text-xs">{r.code}</span> },
    { key: 'induk', header: 'Kategori Induk', cell: (r) => r.category_breakdown?.induk ?? '—' },
    { key: 'anak1', header: 'Kategori Anak 1', cell: (r) => r.category_breakdown?.anak_1 ?? '—' },
    { key: 'anak2', header: 'Kategori Anak 2', cell: (r) => r.category_breakdown?.anak_2 ?? '—' },
    { key: 'anak3', header: 'Kategori Anak 3', cell: (r) => r.category_breakdown?.anak_3 ?? '—' },
    { key: 'description', header: 'Deskripsi Barang', cell: (r) => r.description },
    { key: 'unit', header: 'UOM', cell: (r) => r.unit?.code ?? '—' },
    {
      key: 'blueprint',
      header: 'Perlu Blueprint?',
      cell: (r) => <Badge variant={r.needs_blueprint ? 'warning' : 'neutral'}>{r.needs_blueprint ? 'Ya' : 'Tidak'}</Badge>,
    },
    { key: 'alias', header: 'Nama Alias', cell: (r) => r.alias_name ?? '—' },
    { key: 'gudang', header: 'LETAK GUDANG', cell: (r) => r.default_warehouse?.code ?? '—' },
    { key: 'rak', header: 'LETAK RAK', cell: (r) => r.default_location?.code ?? '—' },
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
    { key: 'status', header: 'Status', cell: (r) => (r.analysis ? <StatusBadge status={r.analysis.status} /> : '—') },
    { key: 'priority', header: 'Prioritas', cell: (r) => (r.analysis ? <PriorityBadge level={r.analysis.priority_level} /> : '—') },
    ...(canEdit || canDelete
      ? [
          {
            key: 'actions',
            header: '',
            cell: (r: Item) => (
              <div className="flex items-center gap-2">
                {canEdit && (
                  <button className="text-muted-foreground hover:text-primary" onClick={() => openEdit(r)} aria-label="Ubah">
                    <Pencil className="size-4" />
                  </button>
                )}
                {canDelete && (
                  <button className="text-muted-foreground hover:text-destructive" onClick={() => remove(r)} aria-label="Hapus">
                    <Trash2 className="size-4" />
                  </button>
                )}
              </div>
            ),
          } satisfies Column<Item>,
        ]
      : []),
  ]

  return (
    <div className="space-y-5">
      <PageHeader
        title="Master Barang"
        subtitle={`${data ? `${data.meta.total} barang` : 'Memuat…'} — sumber tunggal data barang (dari DATA.xlsx, kini dikelola di sini)`}
        icon={<Package className="size-5" />}
        actions={
          hasPermission('item.create') ? (
            <Button size="sm" onClick={openCreate}>
              <Plus className="size-4" />
              Tambah Barang
            </Button>
          ) : undefined
        }
      />

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
      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading} />

      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}

      <ItemFormModal open={formOpen} onClose={() => setFormOpen(false)} item={editing} />
    </div>
  )
}
