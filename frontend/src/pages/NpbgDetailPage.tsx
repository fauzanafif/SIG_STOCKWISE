import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { useParams } from 'react-router-dom'
import { useNpbg, useNpbgAction, useUpdateNpbg, type Npbg } from '@/features/npbg/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { NpbgLine } from '@/features/npbg/api'
import type { ItemLookupResult } from '@/features/inventory/api'

const lineColumns: Column<NpbgLine>[] = [
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

function EditForm({ npbg, onDone }: { npbg: Npbg; onDone: () => void }) {
  const update = useUpdateNpbg(npbg.id)
  const manual = npbg.material_request_id === null
  const [requesterName, setRequesterName] = useState(npbg.requester ?? '')
  const [customerName, setCustomerName] = useState(npbg.customer_name ?? '')
  const [projectName, setProjectName] = useState(npbg.project_name ?? '')
  const [assetRef, setAssetRef] = useState(npbg.asset_ref ?? '')
  const [notes, setNotes] = useState(npbg.notes ?? '')
  const [lines, setLines] = useState<DraftLine[]>(
    (npbg.items ?? []).map((l) => ({ key: crypto.randomUUID(), item_id: l.item_id, code: l.item_code, description: l.description, qty: l.qty })),
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
            Baris NPBG dari request mengikuti reservasi stok — qty tidak bisa diubah di sini, hanya bisa dibatalkan.
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

export function NpbgDetailPage() {
  const { id } = useParams()
  const npbgId = Number(id)
  const { hasPermission } = useAuth()
  const { data: npbg, isLoading } = useNpbg(npbgId)
  const action = useNpbgAction(npbgId)
  const [pickedUpBy, setPickedUpBy] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)

  if (isLoading || !npbg) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  if (editing) return <EditForm npbg={npbg} onDone={() => setEditing(false)} />

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{npbg.number}</h1>
          <p className="text-sm text-muted-foreground">
            {npbg.classification} · {npbg.type}
            {npbg.request_number ? ` · dari ${npbg.request_number}` : ''}
          </p>
        </div>
        <RequestStatusBadge status={npbg.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Peminta" value={npbg.requester} />
          <Field label="Gudang" value={npbg.warehouse?.code} />
          <Field label="Tanggal" value={npbg.date ? new Date(npbg.date).toLocaleDateString('id-ID') : '—'} />
          {npbg.picked_up_by && <Field label="Diambil oleh" value={npbg.picked_up_by} />}
          {npbg.picked_up_at && (
            <Field label="Waktu pickup" value={new Date(npbg.picked_up_at).toLocaleString('id-ID')} />
          )}
          {npbg.cancel_reason && <Field label="Alasan batal" value={npbg.cancel_reason} />}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {['DRAFT', 'PREPARING'].includes(npbg.status) && hasPermission('npbg.update') && (
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
        )}
        {npbg.status === 'PREPARING' && hasPermission('npbg.ready') && (
          <Button size="sm" onClick={() => run('ready')}>Barang Siap</Button>
        )}
        {(npbg.status === 'READY_TO_PICKUP' || npbg.status === 'PREPARING') &&
          hasPermission('npbg.pickup') && (
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
        {!['PICKED_UP', 'COMPLETED', 'CANCELLED'].includes(npbg.status) &&
          hasPermission('npbg.cancel') && (
            <Button size="sm" variant="destructive" onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}>
              Batalkan
            </Button>
          )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={npbg.items ?? []} rowKey={(l) => l.id} />
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
