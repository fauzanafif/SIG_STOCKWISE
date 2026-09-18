import { Link, useParams } from 'react-router-dom'
import { useRi } from '@/features/ri/api'
import { Card, CardContent } from '@/components/ui/card'

function Field({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

function fmtMoney(v: number | null) {
  return v != null ? `Rp ${v.toLocaleString('id-ID')}` : '—'
}

export function RiDetailPage() {
  const { id } = useParams()
  const riId = Number(id)
  const { data: ri, isLoading } = useRi(riId)

  if (isLoading || !ri) return <p className="text-muted-foreground">Memuat…</p>

  return (
    <div className="max-w-3xl space-y-4">
      <div>
        <h1 className="font-mono text-lg font-semibold">{ri.no_ri ?? '—'}</h1>
        <p className="text-sm text-muted-foreground">{fmtDate(ri.tgl_ri)}</p>
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl RI" value={fmtDate(ri.tgl_ri)} />
          <Field label="Divisi" value={ri.divisi} />
          <Field label="Vendor" value={ri.vendor} />
          <div>
            <div className="text-muted-foreground">No PO</div>
            {ri.source_po ? (
              <Link to={`/purchase-orders/${ri.source_po.purchase_order_id}`} className="font-mono text-primary hover:underline">
                {ri.source_po.number ?? ri.no_po ?? '—'}
              </Link>
            ) : (
              <div>{ri.no_po ?? '—'}</div>
            )}
          </div>
          <Field label="Tgl Kirim" value={fmtDate(ri.shipdate)} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={ri.kode_barang} />
          <Field label="Deskripsi Barang" value={ri.deskripsi_barang} />
          <Field label="Kuantitas" value={ri.kuantitas != null ? `${ri.kuantitas} ${ri.satuan ?? ''}` : null} />
          <Field label="Harga Satuan" value={fmtMoney(ri.harga_satuan)} />
          <Field label="Pemeriksa" value={ri.pemeriksa} />
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap">{ri.keterangan ?? '—'}</div>
          </div>
        </CardContent>
      </Card>

      {ri.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(ri.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}
    </div>
  )
}
