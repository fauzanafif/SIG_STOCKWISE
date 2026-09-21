import { useState } from 'react'
import { Package, Pencil, Trash2 } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingAction, useTrackingCreate, useTrackingDelete, useTrackingItem, useTrackingUpdate, type StppRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'
import type { ItemLookupResult } from '@/features/inventory/api'

const columns: Column<StppRow>[] = [
  { key: 'number', header: 'Nomor', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'serial', header: 'No. Seri', cell: (r) => <span className="font-mono text-xs">{r.serial_no ?? '—'}</span> },
  { key: 'desc', header: 'Alat', cell: (r) => (r.item_code ? `${r.item_code} — ${r.description}` : r.description) },
  { key: 'holder', header: 'Pemegang', cell: (r) => r.holder ?? '—' },
  { key: 'out', header: 'Tgl Keluar', cell: (r) => (r.out_date ? new Date(r.out_date).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<StppRow>('stpp')
  const [item, setItem] = useState<ItemLookupResult | null>(null)
  const [serial, setSerial] = useState('')
  const [desc, setDesc] = useState('')
  const [holder, setHolder] = useState('')
  const [placement, setPlacement] = useState('')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Alat (barang ber-serial)</Label>
        {item ? (
          <div className="mt-1 flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{item.code}</span> {item.description}
            </span>
            <button className="text-xs text-destructive" onClick={() => setItem(null)}>
              ganti
            </button>
          </div>
        ) : (
          <ItemPicker onPick={setItem} />
        )}
      </div>
      {!item && (
        <div>
          <Label>Atau deskripsi alat</Label>
          <Input className="mt-1" value={desc} onChange={(e) => setDesc(e.target.value)} />
        </div>
      )}
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>No. Seri</Label>
          <Input className="mt-1" value={serial} onChange={(e) => setSerial(e.target.value)} placeholder="SN-0001" />
        </div>
        <div>
          <Label>Pemegang</Label>
          <Input className="mt-1" value={holder} onChange={(e) => setHolder(e.target.value)} />
        </div>
      </div>
      <div>
        <Label>Penempatan</Label>
        <Input className="mt-1" value={placement} onChange={(e) => setPlacement(e.target.value)} />
      </div>
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || (!item && !desc)}
          onClick={() =>
            create.mutate(
              {
                item_id: item?.id,
                description_raw: item ? undefined : desc,
                serial_no: serial || undefined,
                holder_name_raw: holder || undefined,
                placement_raw: placement || undefined,
              },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Serahkan Alat
        </Button>
      </div>
    </div>
  )
}

function EditForm({ row, onDone }: { row: StppRow; onDone: () => void }) {
  const update = useTrackingUpdate<StppRow>('stpp', row.id)
  const [desc, setDesc] = useState(row.description)
  const [holder, setHolder] = useState(row.holder ?? '')
  const [placement, setPlacement] = useState(row.placement ?? '')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
      <div>
        <Label>Deskripsi alat</Label>
        <Input className="mt-1" value={desc} onChange={(e) => setDesc(e.target.value)} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Pemegang</Label>
          <Input className="mt-1" value={holder} onChange={(e) => setHolder(e.target.value)} />
        </div>
        <div>
          <Label>Penempatan</Label>
          <Input className="mt-1" value={placement} onChange={(e) => setPlacement(e.target.value)} />
        </div>
      </div>
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={update.isPending}
          onClick={() =>
            update.mutate(
              { description_raw: desc, holder_name_raw: holder || undefined, placement_raw: placement || undefined },
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
  // The list row omits fields that need an eager-loaded relation (out_npbg,
  // return_ri) to keep the index query cheap across every module — a fresh
  // fetch here is what actually shows them, not the row that was clicked.
  const { data: row, isLoading, refetch } = useTrackingItem<StppRow>('stpp', id)
  const action = useTrackingAction<StppRow>('stpp', id)
  const del = useTrackingDelete('stpp')
  const [note, setNote] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)

  if (isLoading || !row) return <p className="text-muted-foreground">Memuat…</p>

  const done = (name: string, body?: unknown) =>
    action.mutate(
      { action: name, body },
      {
        onSuccess: () => {
          qc.invalidateQueries({ queryKey: ['stpp'] })
          onDone()
        },
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )

  const remove = () => {
    if (!window.confirm(`Hapus STPP ${row.number}?`)) return
    del.mutate(row.id, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  }

  if (editing)
    return (
      <EditForm
        row={row}
        onDone={() => {
          setEditing(false)
          refetch()
        }}
      />
    )

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{row.number}</span>],
          ['Status', <RequestStatusBadge status={row.status} />],
          ['No. Seri', row.serial_no],
          ['Alat', row.item_code ? `${row.item_code} — ${row.description}` : row.description],
          ['Pemegang', row.holder],
          ['Penempatan', row.placement],
          ['Tgl keluar', row.out_date],
          ['NPBG keluar', row.out_npbg],
          ['Tgl kembali', row.return_date],
          ['RI penarikan', row.return_ri],
        ]}
      />
      {row.status === 'ACTIVE' && hasPermission('stpp.update') && (
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
          <Button size="sm" variant="destructive" onClick={remove} disabled={del.isPending}>
            <Trash2 className="size-4" /> Hapus
          </Button>
        </div>
      )}
      {row.status === 'ACTIVE' && (
        <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
          <Label>Tarik alat (rusak / selesai)</Label>
          <Input placeholder="Catatan penarikan" value={note} onChange={(e) => setNote(e.target.value)} />
          <Button size="sm" disabled={action.isPending} onClick={() => done('withdraw', { return_note: note || undefined })}>
            Tarik Alat (PASSIVE)
          </Button>
        </div>
      )}
      {row.status === 'PASSIVE' && (
        <Button size="sm" variant="outline" disabled={action.isPending} onClick={() => done('reissue')}>
          Serahkan Lagi (baris baru)
        </Button>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
    </div>
  )
}

export function StppPage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<StppRow>
      base="stpp"
      title="STPP"
      subtitle="Serah Terima Peralatan / Perkakas ber-serial ke divisi"
      icon={<Package className="size-5" />}
      columns={columns}
      statuses={['ACTIVE', 'PASSIVE']}
      canCreate={hasPermission('stpp.create')}
      createLabel="Serahkan Alat"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail id={row.id} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
