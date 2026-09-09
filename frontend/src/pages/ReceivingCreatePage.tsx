import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useCreateReceiving, usePo } from '@/features/purchasing/api'
import { useWarehouses } from '@/features/inventory/api'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export function ReceivingCreatePage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const poId = params.get('po') ? Number(params.get('po')) : null

  const { data: po } = usePo(poId)
  const { data: warehouses } = useWarehouses()
  const create = useCreateReceiving()

  const [pickedWarehouse, setPickedWarehouse] = useState<number | ''>('')
  const [suratJalan, setSuratJalan] = useState('')
  const [qty, setQty] = useState<Record<number, number>>({})
  const [err, setErr] = useState<string | null>(null)

  const warehouseId: number | '' = pickedWarehouse !== '' ? pickedWarehouse : (warehouses?.[0]?.id ?? '')
  const setWarehouseId = setPickedWarehouse

  const outstanding = po?.items?.filter((l) => l.qty - l.qty_received > 0) ?? []

  function submit() {
    setErr(null)
    if (!warehouseId) return setErr('Pilih gudang.')
    const lines = outstanding
      .filter((l) => (qty[l.id] ?? 0) > 0)
      .map((l) => ({ purchase_order_item_id: l.id, description_raw: l.description, qty_received: qty[l.id] }))
    if (lines.length === 0) return setErr('Isi minimal 1 qty diterima.')
    create.mutate(
      {
        purchase_order_id: poId ?? undefined,
        warehouse_id: Number(warehouseId),
        surat_jalan_no: suratJalan || undefined,
        lines,
      },
      {
        onSuccess: (ri) => navigate(`/receivings/${ri.id}`),
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )
  }

  if (poId && !po) return <p className="text-muted-foreground">Memuat PO…</p>

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Buat Penerimaan{po ? ` — ${po.number}` : ''}</h1>

      <Card>
        <CardHeader>
          <CardTitle>Detail</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div>
            <Label>Gudang</Label>
            <select
              className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={warehouseId}
              onChange={(e) => setWarehouseId(e.target.value ? Number(e.target.value) : '')}
            >
              <option value="">— pilih gudang —</option>
              {warehouses?.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code} — {w.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>No. Surat Jalan</Label>
            <Input className="mt-1" value={suratJalan} onChange={(e) => setSuratJalan(e.target.value)} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Baris</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          {!po && <p className="text-sm text-muted-foreground">Buka dari PO yang sudah dikirim untuk mengisi baris.</p>}
          {outstanding.map((l) => (
            <div key={l.id} className="flex items-center gap-2 text-sm">
              <span className="flex-1">
                <span className="font-mono text-xs text-muted-foreground">{l.item_code}</span> {l.description}
                <span className="ml-1 text-xs text-muted-foreground">· sisa {l.qty - l.qty_received}</span>
              </span>
              <Input
                type="number"
                min={0}
                max={l.qty - l.qty_received}
                step="any"
                placeholder="qty diterima"
                className="h-8 w-32"
                value={qty[l.id] ?? ''}
                onChange={(e) => setQty((q) => ({ ...q, [l.id]: Number(e.target.value) }))}
              />
            </div>
          ))}
        </CardContent>
      </Card>

      {err && <p className="text-sm text-destructive">{err}</p>}
      <p className="text-xs text-muted-foreground">
        Stok belum bertambah sampai penerimaan dikonfirmasi.
      </p>
      <Button disabled={create.isPending} onClick={submit}>
        Simpan Penerimaan
      </Button>
    </div>
  )
}
