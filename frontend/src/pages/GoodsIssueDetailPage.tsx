import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { useParams } from 'react-router-dom'
import { useGoodsIssue, useGoodsIssueAction, useUpdateGoodsIssue, type GoodsIssue } from '@/features/goods-issues/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { GoodsIssueLine } from '@/features/goods-issues/api'
import type { ItemLookupResult } from '@/features/inventory/api'

const lineColumns: Column<GoodsIssueLine>[] = [
  { key: 'no', header: '#', cell: (l) => l.item_no ?? '—' },
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  { key: 'qty', header: 'Qty', cell: (l) => `${l.qty} ${l.unit ?? ''}` },
  { key: 'issued', header: 'Keluar', cell: (l) => l.qty_issued },
]

interface DraftLine {
  key: string
  item_id: number | null
  code: string | null
  description: string
  qty: number
}

function EditForm({ goodsIssue, onDone }: { goodsIssue: GoodsIssue; onDone: () => void }) {
  const update = useUpdateGoodsIssue(goodsIssue.id)
  const manual = goodsIssue.material_request_id === null
  const [requesterName, setRequesterName] = useState(goodsIssue.requester ?? '')
  const [customerName, setCustomerName] = useState(goodsIssue.customer_name ?? '')
  const [projectName, setProjectName] = useState(goodsIssue.project_name ?? '')
  const [assetRef, setAssetRef] = useState(goodsIssue.asset_ref ?? '')
  const [notes, setNotes] = useState(goodsIssue.notes ?? '')
  const [lines, setLines] = useState<DraftLine[]>(
    (goodsIssue.items ?? []).map((l) => ({ key: crypto.randomUUID(), item_id: l.item_id, code: l.item_code, description: l.description, qty: l.qty })),
  )
  const [err, setErr] = useState<string | null>(null)

  function addItem(it: ItemLookupResult) {
    setLines((ls) => [...ls, { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1 }])
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="grid grid-cols-2 gap-3">
          <Input placeholder="Nama peminta" value={requesterName} onChange={(e) => setRequesterName(e.target.value)} />
          <Input placeholder="Customer (opsional)" value={customerName} onChange={(e) => setCustomerName(e.target.value)} />
          <Input placeholder="Proyek (opsional)" value={projectName} onChange={(e) => setProjectName(e.target.value)} />
          <Input placeholder="Aset (opsional)" value={assetRef} onChange={(e) => setAssetRef(e.target.value)} />
        </div>
        <Input placeholder="Catatan (opsional)" value={notes} onChange={(e) => setNotes(e.target.value)} />

        {manual ? (
          <>
            <ItemPicker onPick={addItem} />
            {lines.map((l) => (
              <div key={l.key} className="flex items-center gap-2 text-sm">
                <span className="flex-1">
                  <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
                </span>
                <Input
                  type="number" min={0} step="any" className="h-8 w-24"
                  value={l.qty}
                  onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))}
                />
                <button className="text-xs text-destructive" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
                  hapus
                </button>
              </div>
            ))}
          </>
        ) : (
          <p className="text-xs text-muted-foreground">
            Baris dari request mengikuti reservasi stok — qty tidak bisa diubah di sini, hanya bisa dibatalkan.
          </p>
        )}

        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="outline" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={update.isPending}
            onClick={() =>
              update.mutate(
                {
                  requester_name: requesterName || undefined,
                  customer_name: customerName || undefined,
                  project_name: projectName || undefined,
                  asset_ref: assetRef || undefined,
                  notes: notes || undefined,
                  items: manual
                    ? lines.map((l) => ({ item_id: l.item_id ?? undefined, description_raw: l.description, qty: l.qty }))
                    : undefined,
                },
                { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
              )
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

export function GoodsIssueDetailPage() {
  const { id } = useParams()
  const goodsIssueId = Number(id)
  const { hasPermission } = useAuth()
  const { data: goodsIssue, isLoading } = useGoodsIssue(goodsIssueId)
  const action = useGoodsIssueAction(goodsIssueId)
  const [pickedUpBy, setPickedUpBy] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)

  if (isLoading || !goodsIssue) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  if (editing) return <EditForm goodsIssue={goodsIssue} onDone={() => setEditing(false)} />

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{goodsIssue.number}</h1>
          <p className="text-sm text-muted-foreground">
            {goodsIssue.classification} · {goodsIssue.type}
            {goodsIssue.request_number ? ` · dari ${goodsIssue.request_number}` : ''}
          </p>
        </div>
        <RequestStatusBadge status={goodsIssue.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Peminta" value={goodsIssue.requester} />
          <Field label="Gudang" value={goodsIssue.warehouse?.code} />
          <Field label="Tanggal" value={goodsIssue.date ? new Date(goodsIssue.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Dibuat" value={new Date(goodsIssue.created_at).toLocaleString('id-ID')} />
          {goodsIssue.picked_up_by && <Field label="Diambil oleh" value={goodsIssue.picked_up_by} />}
          {goodsIssue.picked_up_at && (
            <Field label="Waktu pickup" value={new Date(goodsIssue.picked_up_at).toLocaleString('id-ID')} />
          )}
          {goodsIssue.cancel_reason && <Field label="Alasan batal" value={goodsIssue.cancel_reason} />}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {['DRAFT', 'PREPARING'].includes(goodsIssue.status) && hasPermission('goods_issue.update') && (
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
        )}
        {goodsIssue.status === 'PREPARING' && hasPermission('goods_issue.ready') && (
          <Button size="sm" onClick={() => run('ready')}>Barang Siap</Button>
        )}
        {(goodsIssue.status === 'READY_TO_PICKUP' || goodsIssue.status === 'PREPARING') &&
          hasPermission('goods_issue.pickup') && (
            <>
              <Input
                placeholder="Nama pengambil"
                className="h-9 w-48"
                value={pickedUpBy}
                onChange={(e) => setPickedUpBy(e.target.value)}
              />
              <Button size="sm" disabled={!pickedUpBy} onClick={() => run('pickup', { picked_up_by: pickedUpBy })}>
                Konfirmasi Pickup
              </Button>
            </>
          )}
        {!['PICKED_UP', 'COMPLETED', 'CANCELLED'].includes(goodsIssue.status) &&
          hasPermission('goods_issue.cancel') && (
            <Button size="sm" variant="destructive" onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}>
              Batalkan
            </Button>
          )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={goodsIssue.items ?? []} rowKey={(l) => l.id} />
    </div>
  )
}

function Field({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}
