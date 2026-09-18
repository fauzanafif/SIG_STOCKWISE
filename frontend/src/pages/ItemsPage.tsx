import { useEffect, useMemo, useState } from 'react'
import { Columns3, FileSpreadsheet, FileText, Package, Pencil, Trash2, Plus } from 'lucide-react'
import { useAccurateCategoryOptions, useDeleteItem, useItems } from '@/features/inventory/api'
import { useUnits } from '@/features/requests/api'
import { useAuth } from '@/auth/AuthContext'
import { api, apiErrorMessage } from '@/lib/api'
import { ItemFormModal } from '@/components/ItemFormModal'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Badge, PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { Item } from '@/types/inventory'

const HIDDEN_COLUMNS_STORAGE_KEY = 'stockwise:items-hidden-columns'

function loadHiddenColumns(): Set<string> {
  try {
    const raw = localStorage.getItem(HIDDEN_COLUMNS_STORAGE_KEY)
    return raw ? new Set(JSON.parse(raw) as string[]) : new Set()
  } catch {
    return new Set()
  }
}

export function ItemsPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [induk, setInduk] = useState('')
  const [anak1, setAnak1] = useState('')
  const [anak2, setAnak2] = useState('')
  const [anak3, setAnak3] = useState('')
  const [unitId, setUnitId] = useState('')
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Item | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const del = useDeleteItem()
  const { data: branches } = useAccurateCategoryOptions()
  const { data: units } = useUnits()

  // Filter kategori bertingkat dibangun client-side dari daftar cabang nyata
  // (accurate_category_anak_1/2/3) — sama seperti CategoryPicker.tsx untuk
  // tree Excel. Kategori Induk BUKAN bagian dari tingkatan anak_1/2/3 (itu
  // diturunkan dari 3 huruf depan kode barang, sistem klasifikasi yang
  // berbeda) — jadi dropdown-nya independen, tidak cascading ke anak_1/2/3.
  const indukOptions = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_induk).filter((v): v is string => v != null) ?? [])].sort(),
    [branches]
  )
  const anak1Options = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_anak_1) ?? [])].sort(),
    [branches]
  )
  const anak2Options = useMemo(
    () =>
      [
        ...new Set(
          branches?.filter((b) => b.accurate_category_anak_1 === anak1).map((b) => b.accurate_category_anak_2) ?? []
        ),
      ].filter((v): v is string => v != null).sort(),
    [branches, anak1]
  )
  const anak3Options = useMemo(
    () =>
      [
        ...new Set(
          branches
            ?.filter((b) => b.accurate_category_anak_1 === anak1 && b.accurate_category_anak_2 === anak2)
            .map((b) => b.accurate_category_anak_3) ?? []
        ),
      ].filter((v): v is string => v != null).sort(),
    [branches, anak1, anak2]
  )

  function pickAnak1(v: string) {
    setAnak1(v)
    setAnak2('')
    setAnak3('')
    setPage(1)
  }

  function pickAnak2(v: string) {
    setAnak2(v)
    setAnak3('')
    setPage(1)
  }

  const activeFilters = {
    search: search || undefined,
    status: status || undefined,
    accurate_category_induk: induk || undefined,
    accurate_category_anak_1: anak1 || undefined,
    accurate_category_anak_2: anak2 || undefined,
    accurate_category_anak_3: anak3 || undefined,
    unit_id: unitId ? Number(unitId) : undefined,
  }

  const { data, isLoading, isError } = useItems({ ...activeFilters, page, per_page: 25 })

  const canEdit = hasPermission('item.update')
  const canDelete = hasPermission('item.delete')
  const canExportExcel = hasPermission('export.excel') && hasPermission('report.items')
  const canExportPdf = hasPermission('export.pdf') && hasPermission('report.items')
  const [exporting, setExporting] = useState<'xlsx' | 'pdf' | null>(null)

  async function exportItems(format: 'xlsx' | 'pdf') {
    setExporting(format)
    setErr(null)
    try {
      const res = await api.get('/api/export/items', {
        params: { ...activeFilters, format },
        responseType: 'blob',
      })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const match = disposition.match(/filename="?([^"]+)"?/)
      const name = match?.[1] ?? `master-barang.${format === 'pdf' ? 'html' : format}`
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setExporting(null)
    }
  }

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
    { key: 'code', header: 'Kode Barang', className: 'whitespace-nowrap', cell: (r) => <span className="font-mono text-xs">{r.code}</span> },
    { key: 'induk', header: 'Kategori Induk', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.induk ?? '—' },
    { key: 'anak1', header: 'Kategori Anak 1', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_1 ?? '—' },
    { key: 'anak2', header: 'Kategori Anak 2', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_2 ?? '—' },
    { key: 'anak3', header: 'Kategori Anak 3', className: 'whitespace-nowrap', cell: (r) => r.category_breakdown?.anak_3 ?? '—' },
    { key: 'description', header: 'Deskripsi Barang', className: 'whitespace-nowrap', cell: (r) => r.description },
    { key: 'unit', header: 'UOM', className: 'whitespace-nowrap', cell: (r) => r.unit?.code ?? '—' },
    {
      key: 'qty',
      header: 'QTY',
      className: 'whitespace-nowrap',
      cell: (r) => (r.accurate_qty_onhand != null ? <span className="tabular-nums">{r.accurate_qty_onhand}</span> : '—'),
    },
    {
      key: 'blueprint',
      header: 'Perlu Blueprint?',
      className: 'whitespace-nowrap',
      cell: (r) => <Badge variant={r.needs_blueprint ? 'warning' : 'neutral'}>{r.needs_blueprint ? 'Ya' : 'Tidak'}</Badge>,
    },
    { key: 'alias', header: 'Nama Alias', className: 'whitespace-nowrap', cell: (r) => r.alias_name ?? '—' },
    { key: 'gudang', header: 'Letak Gudang', className: 'whitespace-nowrap', cell: (r) => r.default_warehouse?.code ?? '—' },
    { key: 'rak', header: 'Letak Rak', className: 'whitespace-nowrap', cell: (r) => r.default_location?.code ?? '—' },
    {
      key: 'blueprint_img',
      header: 'Blueprint IMG',
      className: 'whitespace-nowrap',
      cell: (r) => (r.blueprint_img_path ? <span className="font-mono text-xs">{r.blueprint_img_path}</span> : '—'),
    },
    {
      key: 'blueprint_pdf',
      header: 'Blueprint Detail PDF',
      className: 'whitespace-nowrap',
      cell: (r) => (r.blueprint_pdf_path ? <span className="font-mono text-xs">{r.blueprint_pdf_path}</span> : '—'),
    },
    { key: 'lt', header: 'Lead Time', className: 'whitespace-nowrap', cell: (r) => (r.lead_time_days != null ? `${r.lead_time_days} hr` : '—') },
    {
      key: 'available',
      header: 'Tersedia',
      className: 'whitespace-nowrap',
      cell: (r) =>
        r.analysis
          ? r.analysis.stock_known
            ? r.analysis.available
            : <span className="text-muted-foreground">UNKNOWN</span>
          : '—',
    },
    { key: 'ss', header: 'Safety Stock', className: 'whitespace-nowrap', cell: (r) => r.analysis?.safety_stock ?? r.safety_stock ?? '—' },
    { key: 'min_pr', header: 'MIN PR', className: 'whitespace-nowrap', cell: (r) => r.min_pr ?? '—' },
    { key: 'status', header: 'Status', className: 'whitespace-nowrap', cell: (r) => (r.analysis ? <StatusBadge status={r.analysis.status} /> : '—') },
    { key: 'priority', header: 'Prioritas', className: 'whitespace-nowrap', cell: (r) => (r.analysis ? <PriorityBadge level={r.analysis.priority_level} /> : '—') },
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

  const [hiddenCols, setHiddenCols] = useState<Set<string>>(loadHiddenColumns)
  const [colMenuOpen, setColMenuOpen] = useState(false)

  useEffect(() => {
    try {
      localStorage.setItem(HIDDEN_COLUMNS_STORAGE_KEY, JSON.stringify([...hiddenCols]))
    } catch {
      // localStorage tidak tersedia (mode privat, dll) — abaikan, cukup tidak persisten.
    }
  }, [hiddenCols])

  function toggleColumn(key: string) {
    setHiddenCols((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  // Kode Barang & kolom aksi selalu tampil — sisanya bisa disembunyikan.
  const toggleableColumns = columns.filter((c) => c.key !== 'code' && c.key !== 'actions')
  const visibleColumns = columns.filter((c) => c.key === 'code' || c.key === 'actions' || !hiddenCols.has(c.key))

  return (
    <div className="space-y-5">
      <PageHeader
        title="Master Barang"
        subtitle={`${data ? `${data.meta.total} barang` : 'Memuat…'} — sumber tunggal data barang (dari DATA.xlsx, kini dikelola di sini)`}
        icon={<Package className="size-5" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {canExportExcel && (
              <Button size="sm" variant="outline" disabled={exporting === 'xlsx'} onClick={() => exportItems('xlsx')}>
                <FileSpreadsheet className="size-4" />
                {exporting === 'xlsx' ? 'Menyiapkan…' : 'Excel'}
              </Button>
            )}
            {canExportPdf && (
              <Button size="sm" variant="outline" disabled={exporting === 'pdf'} onClick={() => exportItems('pdf')}>
                <FileText className="size-4" />
                {exporting === 'pdf' ? 'Menyiapkan…' : 'PDF'}
              </Button>
            )}
            {hasPermission('item.create') && (
              <Button size="sm" onClick={openCreate}>
                <Plus className="size-4" />
                Tambah Barang
              </Button>
            )}
          </div>
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
          value={induk}
          onChange={(e) => {
            setInduk(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua Kategori Induk</option>
          {indukOptions.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={anak1}
          onChange={(e) => pickAnak1(e.target.value)}
        >
          <option value="">Semua Kategori Anak 1</option>
          {anak1Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:bg-muted disabled:text-muted-foreground"
          value={anak2}
          disabled={!anak1 || anak2Options.length === 0}
          onChange={(e) => pickAnak2(e.target.value)}
        >
          <option value="">Semua Kategori Anak 2</option>
          {anak2Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:bg-muted disabled:text-muted-foreground"
          value={anak3}
          disabled={!anak2 || anak3Options.length === 0}
          onChange={(e) => {
            setAnak3(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua Kategori Anak 3</option>
          {anak3Options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={unitId}
          onChange={(e) => {
            setUnitId(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua UOM</option>
          {units?.map((u) => (
            <option key={u.id} value={u.id}>
              {u.code}
            </option>
          ))}
        </select>
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

        <div className="relative">
          <Button size="sm" variant="outline" onClick={() => setColMenuOpen((v) => !v)}>
            <Columns3 className="size-4" />
            Kolom
          </Button>
          {colMenuOpen && (
            <>
              <div className="fixed inset-0 z-10" onClick={() => setColMenuOpen(false)} />
              <div className="absolute right-0 z-20 mt-1 max-h-80 w-64 overflow-y-auto rounded-md border bg-background p-2 shadow-md">
                {toggleableColumns.map((c) => (
                  <label key={c.key} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/60">
                    <input
                      type="checkbox"
                      checked={!hiddenCols.has(c.key)}
                      onChange={() => toggleColumn(c.key)}
                    />
                    {c.header}
                  </label>
                ))}
              </div>
            </>
          )}
        </div>
      </div>

      {isError && <p className="text-sm text-destructive">Gagal memuat data.</p>}
      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable
        columns={visibleColumns}
        rows={data?.data ?? []}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        containerClassName="max-h-[65vh] overflow-y-auto"
        stickyHeader
      />

      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}

      <ItemFormModal open={formOpen} onClose={() => setFormOpen(false)} item={editing} />
    </div>
  )
}
