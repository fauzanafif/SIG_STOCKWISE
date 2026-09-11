import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ScrollText } from 'lucide-react'
import { useCreateNpbgManual, useNpbgList } from '@/features/npbg/api'
import { useWarehouses } from '@/features/inventory/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Npbg } from '@/features/npbg/api'
import type { ItemLookupResult } from '@/features/inventory/api'

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

interface DraftLine {
  key: string
  item_id: number
  code: string | null
  description: string
  qty: number
}

export function NpbgListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useNpbgList({ search: search || undefined, status: status || undefined, page })

  const { data: warehouses } = useWarehouses()
  const [showForm, setShowForm] = useState(false)
  const [classification, setClassification] = useState('NON_PENJUALAN')
  const [warehouseId, setWarehouseId] = useState('')
  const [requesterName, setRequesterName] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [err, setErr] = useState<string | null>(null)
  const create = useCreateNpbgManual()

  function addItem(it: ItemLookupResult) {
    setLines((ls) =>
      ls.some((l) => l.item_id === it.id)
        ? ls
        : [...ls, { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1 }],
    )
  }

  function submit() {
    setErr(null)
    if (!warehouseId) return setErr('Pilih gudang.')
    if (lines.length === 0) return setErr('Tambahkan minimal 1 barang.')
    create.mutate(
      {
        classification,
        warehouse_id: Number(warehouseId),
        requester_name: requesterName || undefined,
        notes: notes || undefined,
        items: lines.map((l) => ({ item_id: l.item_id, qty: l.qty })),
      },
      {
        onSuccess: () => {
          setLines([])
          setNotes('')
          setRequesterName('')
          setShowForm(false)
        },
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="NPBG"
        subtitle="Nota Pengeluaran Barang Gudang"
        icon={<ScrollText className="size-5" />}
        actions={
          hasPermission('npbg.create') ? (
            <Button size="sm" variant="outline" onClick={() => setShowForm((v) => !v)}>
              {showForm ? 'Tutup' : 'Buat NPBG Manual'}
            </Button>
          ) : undefined
        }
      />

      {showForm && (
        <Card>
          <CardContent className="space-y-3 p-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Select value={classification} onChange={(e) => setClassification(e.target.value)}>
                  <option value="NON_PENJUALAN">Non Penjualan</option>
                  <option value="PENJUALAN">Penjualan</option>
                </Select>
              </div>
              <div>
                <Select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
                  <option value="">— pilih gudang —</option>
                  {warehouses?.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.code} — {w.name}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
            <Input placeholder="Nama peminta (opsional)" value={requesterName} onChange={(e) => setRequesterName(e.target.value)} />
            <ItemPicker onPick={addItem} />
            {lines.map((l) => (
              <div key={l.key} className="flex items-center gap-2 text-sm">
                <span className="flex-1">
                  <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
                </span>
                <Input
                  type="number"
                  min={0}
                  step="any"
                  className="h-8 w-24"
                  value={l.qty}
                  onChange={(e) =>
                    setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))
                  }
                />
                <button className="text-xs text-destructive" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
                  hapus
                </button>
              </div>
            ))}
            <Input placeholder="Catatan (opsional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
            {err && <p className="text-sm text-destructive">{err}</p>}
            <Button size="sm" disabled={create.isPending} onClick={submit}>
              Simpan NPBG
            </Button>
          </CardContent>
        </Card>
      )}

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
