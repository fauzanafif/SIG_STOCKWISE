import { useState } from 'react'
import { Recycle } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingAction, useTrackingCreate, useTrackingItem, type UsedReturnRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { ItemLookupResult } from '@/features/inventory/api'

const columns: Column<UsedReturnRow>[] = [
  { key: 'number', header: 'Nomor', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'npbg', header: 'NPBG Asal', cell: (r) => r.npbg_ref ?? '—' },
  { key: 'items', header: 'Baris', cell: (r) => r.items_count ?? 0 },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.return_date ? new Date(r.return_date).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

interface Line {
  key: string
  item_id?: number
  code?: string
  description: string
  qty: number
  condition: string
  into_stock: boolean
}

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<UsedReturnRow>('used-returns')
  const [npbgRef, setNpbgRef] = useState('')
  const [lines, setLines] = useState<Line[]>([])
  const [err, setErr] = useState<string | null>(null)

  const add = (it: ItemLookupResult) =>
    setLines((ls) => [
      ...ls,
      { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1, condition: 'USED', into_stock: false },
    ])

  return (
    <div className="space-y-3">
      <div>
        <Label>No. NPBG asal</Label>
        <Input className="mt-1" value={npbgRef} onChange={(e) => setNpbgRef(e.target.value)} placeholder="NA/25/VIII/138" />
      </div>
      <div>
        <Label>Barang bekas</Label>
        <ItemPicker onPick={add} />
      </div>
      {lines.map((l) => (
        <div key={l.key} className="space-y-2 rounded-md border p-2 text-sm">
          <div className="flex items-center justify-between">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
            </span>
            <button className="text-xs text-destructive" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
              hapus
            </button>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Input
              type="number"
              step="any"
              className="h-8 w-24"
              value={l.qty}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))}
            />
            <Select
              className="h-8 w-36"
              value={l.condition}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, condition: e.target.value } : x)))}
            >
              <option value="REUSABLE">Reusable</option>
              <option value="USED">Used</option>
              <option value="DAMAGED">Damaged</option>
              <option value="SCRAP">Scrap</option>
            </Select>
            <label className="flex items-center gap-1 text-xs">
              <input
                type="checkbox"
                checked={l.into_stock}
                onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, into_stock: e.target.checked } : x)))}
              />
              masuk stok
            </label>
          </div>
        </div>
      ))}
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || lines.length === 0}
          onClick={() =>
            create.mutate(
              {
                npbg_ref_raw: npbgRef || undefined,
                items: lines.map((l) => ({
                  item_id: l.item_id,
                  qty: l.qty,
                  condition: l.condition,
                  into_stock: l.into_stock,
                })),
              },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Simpan
        </Button>
      </div>
    </div>
  )
}

function Detail({ id, onDone }: { id: number; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const { data: ur, isLoading } = useTrackingItem<UsedReturnRow>('used-returns', id)
  const action = useTrackingAction<UsedReturnRow>('used-returns', id)
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !ur) return <p className="text-muted-foreground">Memuat…</p>

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{ur.number}</span>],
          ['Status', <RequestStatusBadge status={ur.status} />],
          ['NPBG asal', ur.npbg_number ?? ur.npbg_ref],
          ['RI bekas', ur.ri_number],
          ['Tanggal', ur.return_date],
        ]}
      />
      <DataTable
        columns={[
          { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? l.component_type ?? '—'}</span> },
          { key: 'desc', header: 'Deskripsi', cell: (l) => l.description ?? l.component_type ?? '—' },
          { key: 'qty', header: 'Qty', cell: (l) => l.qty },
          { key: 'cond', header: 'Kondisi', cell: (l) => <Badge variant="neutral">{l.condition}</Badge> },
          { key: 'stock', header: 'Masuk Stok', cell: (l) => (l.into_stock ? 'Ya' : 'Tidak') },
        ]}
        rows={ur.items ?? []}
        rowKey={(l) => l.id}
      />
      {ur.status === 'PENDING' && hasPermission('used_return.close') && (
        <Button
          size="sm"
          disabled={action.isPending}
          onClick={() =>
            action.mutate(
              { action: 'close', body: {} },
              {
                onSuccess: () => {
                  qc.invalidateQueries({ queryKey: ['used-returns'] })
                  onDone()
                },
                onError: (e) => setErr(apiErrorMessage(e)),
              },
            )
          }
        >
          Tutup (bekas sudah masuk RI)
        </Button>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
    </div>
  )
}

export function UsedReturnPage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<UsedReturnRow>
      base="used-returns"
      title="Pengembalian Bekas"
      subtitle="Sisa / bekas / rusak dari pemakaian yang dikembalikan"
      icon={<Recycle className="size-5" />}
      columns={columns}
      statuses={['PENDING', 'CLEAR']}
      canCreate={hasPermission('used_return.create')}
      createLabel="Catat Pengembalian"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail id={row.id} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
