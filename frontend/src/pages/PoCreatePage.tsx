import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useCreatePo, usePurchaseProposal, useVendors } from '@/features/purchasing/api'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

interface Line {
  key: string
  ppb_item_id?: number
  item_id?: number
  description: string
  qty: number
  unit_price: number
}

export function PoCreatePage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const ppbId = params.get('ppb') ? Number(params.get('ppb')) : null

  const { data: ppb } = usePurchaseProposal(ppbId)
  const { data: vendors } = useVendors()
  const create = useCreatePo()

  const [vendorId, setVendorId] = useState<number | ''>('')
  const [taxPercent, setTaxPercent] = useState(0)
  const [err, setErr] = useState<string | null>(null)
  const [prices, setPrices] = useState<Record<number, number>>({})

  const lines: Line[] = useMemo(() => {
    if (!ppb?.items) return []
    return ppb.items
      .filter((l) => l.line_status !== 'CLOSED')
      .map((l) => ({
        key: String(l.id),
        ppb_item_id: l.id,
        item_id: undefined,
        description: l.description,
        qty: l.qty - l.qty_ordered,
        unit_price: prices[l.id] ?? 0,
      }))
      .filter((l) => l.qty > 0)
  }, [ppb, prices])

  const subtotal = lines.reduce((s, l) => s + l.qty * l.unit_price, 0)
  const total = subtotal + (subtotal * taxPercent) / 100

  function submit() {
    setErr(null)
    if (!vendorId) return setErr('Pilih vendor.')
    if (lines.length === 0) return setErr('Tidak ada baris untuk dipesan.')
    create.mutate(
      {
        vendor_id: Number(vendorId),
        ppb_id: ppbId ?? undefined,
        tax_percent: taxPercent || undefined,
        lines: lines.map((l) => ({
          ppb_item_id: l.ppb_item_id,
          description_raw: l.description,
          qty: l.qty,
          unit_price: l.unit_price,
        })),
      },
      {
        onSuccess: (po) => navigate(`/purchase-orders/${po.id}`),
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )
  }

  if (ppbId && !ppb) return <p className="text-muted-foreground">Memuat usulan pembelian…</p>

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Buat Purchase Order{ppb ? ` — ${ppb.number}` : ''}</h1>

      <Card>
        <CardHeader>
          <CardTitle>Detail</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div>
            <Label>Vendor</Label>
            <select
              className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={vendorId}
              onChange={(e) => setVendorId(e.target.value ? Number(e.target.value) : '')}
            >
              <option value="">— pilih vendor —</option>
              {vendors?.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>PPN (%)</Label>
            <Input
              type="number"
              min={0}
              max={100}
              className="mt-1 w-32"
              value={taxPercent}
              onChange={(e) => setTaxPercent(Number(e.target.value))}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Baris</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          {!ppb && <p className="text-sm text-muted-foreground">Buka dari usulan pembelian yang sudah disetujui untuk mengisi baris.</p>}
          {ppb?.items
            ?.filter((l) => l.line_status !== 'CLOSED' && l.qty - l.qty_ordered > 0)
            .map((l) => (
              <div key={l.id} className="flex items-center gap-2 text-sm">
                <span className="flex-1">
                  <span className="font-mono text-xs text-muted-foreground">{l.item_code}</span> {l.description}
                  <span className="ml-1 text-xs text-muted-foreground">· qty {l.qty - l.qty_ordered}</span>
                </span>
                <Input
                  type="number"
                  min={0}
                  step="any"
                  placeholder="harga satuan"
                  className="h-8 w-36"
                  value={prices[l.id] ?? ''}
                  onChange={(e) => setPrices((p) => ({ ...p, [l.id]: Number(e.target.value) }))}
                />
              </div>
            ))}
          <div className="border-t pt-2 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Subtotal</span>
              <span>Rp {subtotal.toLocaleString('id-ID')}</span>
            </div>
            <div className="flex justify-between font-medium">
              <span>Total</span>
              <span>Rp {total.toLocaleString('id-ID')}</span>
            </div>
          </div>
        </CardContent>
      </Card>

      {err && <p className="text-sm text-destructive">{err}</p>}
      <Button disabled={create.isPending} onClick={submit}>
        Simpan PO
      </Button>
    </div>
  )
}
