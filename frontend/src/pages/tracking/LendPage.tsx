import { useState } from 'react'
import { Handshake, Pencil, Trash2 } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingAction, useTrackingCreate, useTrackingDelete, useTrackingUpdate, type LendRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'
import type { ItemLookupResult } from '@/features/inventory/api'

const columns: Column<LendRow>[] = [
  { key: 'number', header: 'Nomor', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'desc', header: 'Barang', cell: (r) => r.item_code ? `${r.item_code} — ${r.description}` : r.description },
  { key: 'qty', header: 'Qty', cell: (r) => `${r.qty_returned}/${r.qty} ${r.unit ?? ''}` },
  { key: 'borrower', header: 'Peminjam', cell: (r) => r.borrower_name ?? '—' },
  { key: 'due', header: 'Jatuh Tempo', cell: (r) => (r.due_date ? new Date(r.due_date).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<LendRow>('lend')
  const [item, setItem] = useState<ItemLookupResult | null>(null)
  const [desc, setDesc] = useState('')
  const [qty, setQty] = useState(1)
  const [purpose, setPurpose] = useState('RELASI')
  const [borrower, setBorrower] = useState('')
  const [estDays, setEstDays] = useState(7)
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Barang</Label>
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
          <Label>Atau deskripsi bebas</Label>
          <Input className="mt-1" value={desc} onChange={(e) => setDesc(e.target.value)} />
        </div>
      )}
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Qty</Label>
          <Input type="number" min={1} step="any" className="mt-1" value={qty} onChange={(e) => setQty(Number(e.target.value))} />
        </div>
        <div>
          <Label>Estimasi hari</Label>
          <Input type="number" min={1} className="mt-1" value={estDays} onChange={(e) => setEstDays(Number(e.target.value))} />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Tujuan</Label>
          <Select className="mt-1" value={purpose} onChange={(e) => setPurpose(e.target.value)}>
            <option value="RELASI">Relasi</option>
            <option value="PROJECT">Proyek</option>
            <option value="INTERNAL">Internal</option>
          </Select>
        </div>
        <div>
          <Label>Peminjam</Label>
          <Input className="mt-1" value={borrower} onChange={(e) => setBorrower(e.target.value)} />
        </div>
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
                qty,
                purpose,
                borrower_name: borrower || undefined,
                est_days: estDays || undefined,
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

function EditForm({ row, onDone }: { row: LendRow; onDone: () => void }) {
  const update = useTrackingUpdate<LendRow>('lend', row.id)
  const [qty, setQty] = useState(row.qty)
  const [borrower, setBorrower] = useState(row.borrower_name ?? '')
  const [purpose, setPurpose] = useState(row.purpose)
  const [estDays, setEstDays] = useState('')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Qty</Label>
          <Input type="number" min={0} step="any" className="mt-1" value={qty} onChange={(e) => setQty(Number(e.target.value))} />
        </div>
        <div>
          <Label>Peminjam</Label>
          <Input className="mt-1" value={borrower} onChange={(e) => setBorrower(e.target.value)} />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Tujuan</Label>
          <Select className="mt-1" value={purpose} onChange={(e) => setPurpose(e.target.value)}>
            <option value="RELASI">Relasi</option>
            <option value="PROJECT">Proyek</option>
            <option value="INTERNAL">Internal</option>
          </Select>
        </div>
        <div>
          <Label>Estimasi hari (kosongkan bila tak berubah)</Label>
          <Input type="number" min={1} className="mt-1" value={estDays} onChange={(e) => setEstDays(e.target.value)} />
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
              { qty, borrower_name: borrower || undefined, purpose, est_days: estDays ? Number(estDays) : undefined },
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

function Detail({ row, onDone }: { row: LendRow; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const action = useTrackingAction<LendRow>('lend', row.id)
  const del = useTrackingDelete('lend')
  const [qty, setQty] = useState(Math.max(row.qty - row.qty_returned, 0))
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const open = !['RETURNED'].includes(row.status)
  const editable = row.status === 'ON_LOAN' && row.qty_returned === 0

  function remove() {
    if (!window.confirm(`Hapus Lend ${row.number}?`)) return
    del.mutate(row.id, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
  }

  if (editing) return <EditForm row={row} onDone={() => setEditing(false)} />

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{row.number}</span>],
          ['Status', <RequestStatusBadge status={row.status} />],
          ['Barang', row.item_code ? `${row.item_code} — ${row.description}` : row.description],
          ['Qty dipinjam', `${row.qty} ${row.unit ?? ''}`],
          ['Qty kembali', row.qty_returned],
          ['Tujuan', row.purpose],
          ['Peminjam', row.borrower_name],
          ['Tgl keluar', row.out_date],
          ['Jatuh tempo', row.due_date],
          ['NPBG keluar', row.out_npbg],
          ['RI kembali', row.return_ri],
        ]}
      />
      {editable && (hasPermission('lend.update')) && (
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
          <Button size="sm" variant="destructive" onClick={remove} disabled={del.isPending}>
            <Trash2 className="size-4" /> Hapus
          </Button>
        </div>
      )}
      {open && (
        <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
          <Label>Catat pengembalian</Label>
          <div className="flex items-center gap-2">
            <Input type="number" min={0} step="any" className="w-32" value={qty} onChange={(e) => setQty(Number(e.target.value))} />
            <Button
              size="sm"
              disabled={action.isPending || qty <= 0}
              onClick={() =>
                action.mutate(
                  { action: 'return', body: { qty } },
                  {
                    onSuccess: () => {
                      qc.invalidateQueries({ queryKey: ['lend'] })
                      onDone()
                    },
                    onError: (e) => setErr(apiErrorMessage(e)),
                  },
                )
              }
            >
              Proses Kembali
            </Button>
          </div>
          {err && <p className="text-sm text-destructive">{err}</p>}
        </div>
      )}
    </div>
  )
}

export function LendPage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<LendRow>
      base="lend"
      title="Peminjaman (Lend)"
      subtitle="Barang SIG yang dipinjamkan ke relasi / proyek"
      icon={<Handshake className="size-5" />}
      columns={columns}
      statuses={['ON_LOAN', 'PARTIAL_RETURN', 'RETURNED', 'OVERDUE']}
      canCreate={hasPermission('lend.create')}
      createLabel="Pinjamkan Barang"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail row={row} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
