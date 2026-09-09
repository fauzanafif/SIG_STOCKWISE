import { useState } from 'react'
import { ArrowLeftRight } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTrackingAction, useTrackingCreate, useVendorOptions, type BorrowRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'

const columns: Column<BorrowRow>[] = [
  { key: 'number', header: 'Nomor', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'desc', header: 'Barang', cell: (r) => r.description },
  { key: 'qty', header: 'Qty', cell: (r) => `${r.qty_returned}/${r.qty} ${r.unit ?? ''}` },
  { key: 'lender', header: 'Dipinjam dari', cell: (r) => r.lender_name ?? '—' },
  { key: 'date', header: 'Tgl Pinjam', cell: (r) => (r.borrowed_at ? new Date(r.borrowed_at).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useTrackingCreate<BorrowRow>('borrow')
  const { data: vendors } = useVendorOptions()
  const [desc, setDesc] = useState('')
  const [qty, setQty] = useState(1)
  const [lenderVendorId, setLenderVendorId] = useState('')
  const [lenderName, setLenderName] = useState('')
  const [receipt, setReceipt] = useState('')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Deskripsi barang</Label>
        <Input className="mt-1" value={desc} onChange={(e) => setDesc(e.target.value)} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Qty</Label>
          <Input type="number" min={1} step="any" className="mt-1" value={qty} onChange={(e) => setQty(Number(e.target.value))} />
        </div>
        <div>
          <Label>No. Tanda Terima</Label>
          <Input className="mt-1" value={receipt} onChange={(e) => setReceipt(e.target.value)} />
        </div>
      </div>
      <div>
        <Label>Vendor pemberi pinjaman</Label>
        <Select className="mt-1" value={lenderVendorId} onChange={(e) => setLenderVendorId(e.target.value)}>
          <option value="">— pilih vendor / isi nama manual —</option>
          {vendors?.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}
            </option>
          ))}
        </Select>
      </div>
      {!lenderVendorId && (
        <div>
          <Label>Nama pemberi pinjaman</Label>
          <Input className="mt-1" value={lenderName} onChange={(e) => setLenderName(e.target.value)} />
        </div>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || !desc}
          onClick={() =>
            create.mutate(
              {
                description_raw: desc,
                qty,
                lender_vendor_id: lenderVendorId ? Number(lenderVendorId) : undefined,
                lender_name: lenderName || undefined,
                receipt_ref: receipt || undefined,
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

function Detail({ row, onDone }: { row: BorrowRow; onDone: () => void }) {
  const qc = useQueryClient()
  const action = useTrackingAction<BorrowRow>('borrow', row.id)
  const [qty, setQty] = useState(Math.max(row.qty - row.qty_returned, 0))
  const [err, setErr] = useState<string | null>(null)
  const open = row.status !== 'RETURNED'

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{row.number}</span>],
          ['Status', <RequestStatusBadge status={row.status} />],
          ['Barang', row.description],
          ['Qty dipinjam', `${row.qty} ${row.unit ?? ''}`],
          ['Qty dikembalikan', row.qty_returned],
          ['Dari', row.lender_name],
          ['No. Tanda Terima', row.receipt_ref],
          ['Tgl pinjam', row.borrowed_at],
          ['NPBG pengembalian', row.return_npbg],
        ]}
      />
      {open && (
        <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
          <Label>Catat pengembalian (via NPBG keluar)</Label>
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
                      qc.invalidateQueries({ queryKey: ['borrow'] })
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

export function BorrowPage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<BorrowRow>
      base="borrow"
      title="Pinjam Luar (Borrow)"
      subtitle="Barang milik pihak luar yang dipinjam SIG"
      icon={<ArrowLeftRight className="size-5" />}
      columns={columns}
      statuses={['BORROWED', 'PARTIAL', 'RETURNED']}
      canCreate={hasPermission('borrow.create')}
      createLabel="Catat Pinjaman"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail row={row} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
