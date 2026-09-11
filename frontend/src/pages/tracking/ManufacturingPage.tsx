import { useState } from 'react'
import { Boxes, Pencil, Trash2 } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { api, apiErrorMessage } from '@/lib/api'
import { useTrackingCreate, useTrackingItem, useVendorOptions, type ManufacturingRow } from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'

const columns: Column<ManufacturingRow>[] = [
  { key: 'number', header: 'Nomor', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'kind', header: 'Jenis', cell: (r) => <Badge variant="neutral">{r.kind}</Badge> },
  { key: 'product', header: 'Produk', cell: (r) => r.product_name ?? '—' },
  { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor_name ?? '—' },
  { key: 'subs', header: 'Sub', cell: (r) => r.subs_count ?? 0 },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const { user } = useAuth()
  const create = useTrackingCreate<ManufacturingRow>('manufacturing-orders')
  const { data: vendors } = useVendorOptions()
  const [kind, setKind] = useState('ASSEMBLY')
  const [product, setProduct] = useState('')
  const [vendorId, setVendorId] = useState('')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Jenis</Label>
        <Select className="mt-1" value={kind} onChange={(e) => setKind(e.target.value)}>
          <option value="ASSEMBLY">Assembly (dikerjakan internal)</option>
          <option value="JASA">Jasa (vendor luar)</option>
        </Select>
      </div>
      <div>
        <Label>Nama produk / hasil</Label>
        <Input className="mt-1" value={product} onChange={(e) => setProduct(e.target.value)} />
      </div>
      {kind === 'JASA' && (
        <div>
          <Label>Vendor</Label>
          <Select className="mt-1" value={vendorId} onChange={(e) => setVendorId(e.target.value)}>
            <option value="">— pilih vendor —</option>
            {vendors?.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </Select>
        </div>
      )}
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || !user?.site}
          onClick={() =>
            create.mutate(
              {
                kind,
                product_name: product || undefined,
                site_id: user!.site!.id,
                vendor_id: vendorId ? Number(vendorId) : undefined,
              },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Buat Order
        </Button>
      </div>
    </div>
  )
}

function Detail({ id, onDone }: { id: number; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const { data: order, isLoading, refetch } = useTrackingItem<ManufacturingRow>('manufacturing-orders', id)
  const [process, setProcess] = useState('')
  const [serial, setSerial] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [editingOrder, setEditingOrder] = useState(false)
  const [editProduct, setEditProduct] = useState('')
  const [editingSub, setEditingSub] = useState<number | null>(null)
  const [editProcess, setEditProcess] = useState('')

  if (isLoading || !order) return <p className="text-muted-foreground">Memuat…</p>

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['manufacturing-orders'] })
    refetch()
  }

  const saveOrder = async () => {
    setBusy(true)
    setErr(null)
    try {
      await api.put(`/api/manufacturing-orders/${id}`, { product_name: editProduct })
      setEditingOrder(false)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const removeOrder = async () => {
    if (!window.confirm(`Hapus order ${order.number}?`)) return
    setBusy(true)
    setErr(null)
    try {
      await api.delete(`/api/manufacturing-orders/${id}`)
      qc.invalidateQueries({ queryKey: ['manufacturing-orders'] })
      onDone()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const saveSub = async (subId: number) => {
    setBusy(true)
    setErr(null)
    try {
      await api.put(`/api/manufacturing-subs/${subId}`, { process: editProcess })
      setEditingSub(null)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const removeSub = async (subId: number) => {
    if (!window.confirm('Hapus tahapan ini?')) return
    setBusy(true)
    setErr(null)
    try {
      await api.delete(`/api/manufacturing-subs/${subId}`)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const addSub = async () => {
    setBusy(true)
    setErr(null)
    try {
      await api.post(`/api/manufacturing-orders/${id}/subs`, {
        process: process || undefined,
        serial_no_raw: serial || undefined,
      })
      setProcess('')
      setSerial('')
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const completeSub = async (subId: number) => {
    setBusy(true)
    setErr(null)
    try {
      await api.post(`/api/manufacturing-subs/${subId}/complete`, {})
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const canEditOrder = hasPermission('manufacturing.update') && ['REQUESTED', 'ON_GOING'].includes(order.status)
  const canDeleteOrder = canEditOrder && order.status === 'REQUESTED' && (order.subs_count ?? order.subs?.length ?? 0) === 0

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['Nomor', <span className="font-mono text-xs">{order.number}</span>],
          ['Jenis', order.kind],
          ['Status', <RequestStatusBadge status={order.status} />],
          [
            'Produk',
            editingOrder ? (
              <div className="flex items-center gap-2">
                <Input className="h-8" value={editProduct} onChange={(e) => setEditProduct(e.target.value)} />
                <Button size="sm" disabled={busy} onClick={saveOrder}>Simpan</Button>
                <Button size="sm" variant="outline" onClick={() => setEditingOrder(false)}>Batal</Button>
              </div>
            ) : (
              order.product_name
            ),
          ],
          ['Vendor', order.vendor_name],
          ['Tanggal', order.date],
          ['Selesai', order.completed_at],
        ]}
      />

      {canEditOrder && !editingOrder && (
        <div className="flex gap-2">
          <Button
            size="sm" variant="outline"
            onClick={() => { setEditProduct(order.product_name ?? ''); setEditingOrder(true) }}
          >
            <Pencil className="size-4" /> Ubah Produk
          </Button>
          {canDeleteOrder && (
            <Button size="sm" variant="destructive" disabled={busy} onClick={removeOrder}>
              <Trash2 className="size-4" /> Hapus Order
            </Button>
          )}
        </div>
      )}

      <div>
        <div className="mb-2 text-sm font-medium">Tahapan proses</div>
        <div className="space-y-2">
          {(order.subs ?? []).map((s) => (
            <div key={s.id} className="rounded-lg border p-3 text-sm">
              <div className="flex items-center justify-between">
                {editingSub === s.id ? (
                  <div className="flex flex-1 items-center gap-2">
                    <Input className="h-8" value={editProcess} onChange={(e) => setEditProcess(e.target.value)} />
                    <Button size="sm" disabled={busy} onClick={() => saveSub(s.id)}>Simpan</Button>
                    <Button size="sm" variant="outline" onClick={() => setEditingSub(null)}>Batal</Button>
                  </div>
                ) : (
                  <span>
                    <span className="font-medium">{s.sub_no}</span> · {s.process ?? '—'}
                    {s.serial_no ? <span className="ml-1 font-mono text-xs text-muted-foreground">{s.serial_no}</span> : null}
                  </span>
                )}
                <div className="flex items-center gap-2">
                  <RequestStatusBadge status={s.status} />
                </div>
              </div>
              {s.status !== 'COMPLETED' && editingSub !== s.id && (
                <div className="mt-2 flex gap-2">
                  {hasPermission('manufacturing.update') && (
                    <>
                      <Button
                        size="sm" variant="outline"
                        onClick={() => { setEditProcess(s.process ?? ''); setEditingSub(s.id) }}
                      >
                        <Pencil className="size-4" /> Ubah
                      </Button>
                      <Button size="sm" variant="destructive" disabled={busy} onClick={() => removeSub(s.id)}>
                        <Trash2 className="size-4" /> Hapus
                      </Button>
                    </>
                  )}
                  {hasPermission('manufacturing.complete') && (
                    <Button size="sm" disabled={busy} onClick={() => completeSub(s.id)}>
                      Selesai
                    </Button>
                  )}
                </div>
              )}
            </div>
          ))}
          {(order.subs?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">Belum ada tahapan.</p>}
        </div>
      </div>

      {hasPermission('manufacturing.update') && order.status !== 'COMPLETED' && (
        <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
          <Label>Tambah tahapan</Label>
          <Input placeholder="Proses / routing step" value={process} onChange={(e) => setProcess(e.target.value)} />
          <Input placeholder="No. seri produk (opsional)" value={serial} onChange={(e) => setSerial(e.target.value)} />
          <Button size="sm" disabled={busy} onClick={addSub}>
            Tambah Tahapan
          </Button>
        </div>
      )}

      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end">
        <Button size="sm" variant="outline" onClick={onDone}>
          Tutup
        </Button>
      </div>
    </div>
  )
}

export function ManufacturingPage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<ManufacturingRow>
      base="manufacturing-orders"
      title="Manufaktur & Assembly"
      subtitle="Perakitan / jasa produksi (MA / MJ + tahapan)"
      icon={<Boxes className="size-5" />}
      columns={columns}
      statuses={['REQUESTED', 'ON_GOING', 'COMPLETED', 'CANCELLED']}
      canCreate={hasPermission('manufacturing.create')}
      createLabel="Buat Order"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail id={row.id} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
