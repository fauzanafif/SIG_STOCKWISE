import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useCreatePurchaseProposal, usePurchaseProposalList, type PurchaseProposal } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { FileSpreadsheet } from 'lucide-react'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { ItemLookupResult } from '@/features/inventory/api'

const columns: Column<PurchaseProposal>[] = [
  {
    key: 'number',
    header: 'Nomor',
    cell: (r) => (
      <Link to={`/purchase-proposals/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.number}
      </Link>
    ),
  },
  { key: 'src', header: 'Dari Request', cell: (r) => r.source_request_id ?? '—' },
  { key: 'items', header: 'Item', cell: (r) => r.items_count ?? '—' },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.date ? new Date(r.date).toLocaleDateString('id-ID') : '—') },
]

interface DraftLine {
  key: string
  item_id: number | null
  code: string | null
  description: string
  qty: number
}

export function PurchaseProposalListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = usePurchaseProposalList({ search: search || undefined, status: status || undefined, page })

  const [showForm, setShowForm] = useState(false)
  const [lines, setLines] = useState<DraftLine[]>([])
  const [notes, setNotes] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const create = useCreatePurchaseProposal()

  function addItem(it: ItemLookupResult) {
    setLines((ls) =>
      ls.some((l) => l.item_id === it.id)
        ? ls
        : [...ls, { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1 }],
    )
  }

  function submit() {
    setErr(null)
    if (lines.length === 0) return setErr('Tambahkan minimal 1 barang.')
    create.mutate(
      { notes: notes || undefined, items: lines.map((l) => ({ item_id: l.item_id ?? undefined, description_raw: l.description, qty: l.qty })) },
      {
        onSuccess: () => {
          setLines([])
          setNotes('')
          setShowForm(false)
        },
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Usulan Pembelian (Internal)"
        subtitle="Alur draft → submit → review → approve → PO dari kekurangan stok"
        icon={<FileSpreadsheet className="size-5" />}
        actions={
          hasPermission('purchase_proposal.create') ? (
            <Button size="sm" variant="outline" onClick={() => setShowForm((v) => !v)}>
              {showForm ? 'Tutup' : 'Buat Usulan Manual'}
            </Button>
          ) : undefined
        }
      />

      {showForm && (
        <Card>
          <CardContent className="space-y-3 p-4">
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
              Simpan
            </Button>
          </CardContent>
        </Card>
      )}

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
          {['DRAFT', 'SUBMITTED', 'REVIEW', 'APPROVED', 'ORDERED', 'PARTIAL_RECEIVED', 'RECEIVED', 'CANCELLED'].map((s) => (
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
