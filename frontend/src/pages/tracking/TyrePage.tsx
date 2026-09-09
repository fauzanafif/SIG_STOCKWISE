import { useState } from 'react'
import { Truck } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingAction, useTrackingCreate, type AssetOption, type TyreRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { AssetPicker } from '@/components/AssetPicker'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'

const POSITIONS = ['FRONT_L', 'FRONT_R', 'REAR_LO', 'REAR_LI', 'REAR_RO', 'REAR_RI', 'SPARE']

const columns: Column<TyreRow>[] = [
  { key: 'asset', header: 'Kendaraan', cell: (r) => `${r.asset_code ?? ''} ${r.asset_name ?? ''}`.trim() || '—' },
  { key: 'pos', header: 'Posisi', cell: (r) => r.position ?? '—' },
  { key: 'seq', header: 'Ke-', cell: (r) => r.change_seq ?? '—' },
  { key: 'new', header: 'Ban Baru', cell: (r) => r.new_tyre_desc ?? '—' },
  { key: 'date', header: 'Tanggal', cell: (r) => (r.change_date ? new Date(r.change_date).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<TyreRow>('tyre-changes')
  const [asset, setAsset] = useState<AssetOption | null>(null)
  const [position, setPosition] = useState('FRONT_L')
  const [newDesc, setNewDesc] = useState('')
  const [newSerial, setNewSerial] = useState('')
  const [oldDesc, setOldDesc] = useState('')
  const [oldSerial, setOldSerial] = useState('')
  const [isOpening, setIsOpening] = useState(false)
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Kendaraan</Label>
        {asset ? (
          <div className="mt-1 flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{asset.code}</span> {asset.name}
            </span>
            <button className="text-xs text-destructive" onClick={() => setAsset(null)}>
              ganti
            </button>
          </div>
        ) : (
          <AssetPicker onPick={setAsset} />
        )}
      </div>
      <div>
        <Label>Posisi ban</Label>
        <Select className="mt-1" value={position} onChange={(e) => setPosition(e.target.value)}>
          {POSITIONS.map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </Select>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Ban baru — deskripsi</Label>
          <Input className="mt-1" value={newDesc} onChange={(e) => setNewDesc(e.target.value)} />
        </div>
        <div>
          <Label>No. seri baru</Label>
          <Input className="mt-1" value={newSerial} onChange={(e) => setNewSerial(e.target.value)} />
        </div>
        <div>
          <Label>Ban lama — deskripsi</Label>
          <Input className="mt-1" value={oldDesc} onChange={(e) => setOldDesc(e.target.value)} />
        </div>
        <div>
          <Label>No. seri lama</Label>
          <Input className="mt-1" value={oldSerial} onChange={(e) => setOldSerial(e.target.value)} />
        </div>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={isOpening} onChange={(e) => setIsOpening(e.target.checked)} />
        Pendataan awal (langsung CLEAR, tanpa RI)
      </label>
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || !asset}
          onClick={() =>
            create.mutate(
              {
                asset_id: asset!.id,
                position,
                new_tyre_desc: newDesc || undefined,
                new_serial_raw: newSerial || undefined,
                old_tyre_desc: oldDesc || undefined,
                old_serial_raw: oldSerial || undefined,
                is_opening: isOpening,
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

function Detail({ row, onDone }: { row: TyreRow; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const action = useTrackingAction<TyreRow>('tyre-changes', row.id)
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Kendaraan', `${row.asset_code ?? ''} ${row.asset_name ?? ''}`],
          ['Status', <RequestStatusBadge status={row.status} />],
          ['Posisi', row.position],
          ['Penggantian ke', row.change_seq],
          ['Ban baru', row.new_tyre_desc],
          ['Serial baru', row.new_serial_raw],
          ['Ban lama', row.old_tyre_desc],
          ['Serial lama', row.old_serial_raw],
          ['Tgl ganti', row.change_date],
          ['NPBG keluar', row.out_npbg],
          ['RI ban lama', row.in_ri],
          ['Pendataan awal', row.is_opening ? 'Ya' : 'Tidak'],
        ]}
      />
      {row.status === 'PENDING_RI' && hasPermission('tyre.close') && (
        <Button
          size="sm"
          disabled={action.isPending}
          onClick={() =>
            action.mutate(
              { action: 'close', body: {} },
              {
                onSuccess: () => {
                  qc.invalidateQueries({ queryKey: ['tyre-changes'] })
                  onDone()
                },
                onError: (e) => setErr(apiErrorMessage(e)),
              },
            )
          }
        >
          Tutup (ban lama sudah masuk RI)
        </Button>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
    </div>
  )
}

export function TyrePage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<TyreRow>
      base="tyre-changes"
      title="Ban Luar"
      subtitle="Penggantian ban kendaraan operasional"
      icon={<Truck className="size-5" />}
      columns={columns}
      statuses={['PENDING_RI', 'CLEAR']}
      canCreate={hasPermission('tyre.create')}
      createLabel="Catat Penggantian"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail row={row} onDone={close} />}
      detailTitle={(row) => `${row.asset_code ?? 'Ban'} · ${row.position ?? ''}`}
    />
  )
}
