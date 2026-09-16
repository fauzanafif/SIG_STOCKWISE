import { useParams } from 'react-router-dom'
import { usePpb } from '@/features/ppb/api'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

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

export function PpbDetailPage() {
  const { id } = useParams()
  const ppbId = Number(id)
  const { data: ppb, isLoading } = usePpb(ppbId)

  if (isLoading || !ppb) return <p className="text-muted-foreground">Memuat…</p>

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{ppb.no_ppb ?? '—'}</h1>
          <p className="text-sm text-muted-foreground">{fmtDate(ppb.tgl_ppb)}</p>
        </div>
        {ppb.status && <Badge variant={ppb.status === 'CLOSED' ? 'success' : 'warning'}>{ppb.status}</Badge>}
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl PPB" value={fmtDate(ppb.tgl_ppb)} />
          <Field label="Status" value={ppb.status} />
          <Field label="Divisi" value={ppb.divisi} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={ppb.kode_barang} />
          <Field label="Deskripsi Barang" value={ppb.deskripsi_barang} />
          <Field label="Kuantitas" value={ppb.kuantitas != null ? `${ppb.kuantitas} ${ppb.satuan ?? ''}` : null} />
          <Field label="Qty Dipesan" value={ppb.qty_dipesan} />
          <Field label="Qty Diterima" value={ppb.qty_diterima} />
          <Field label="Peminta" value={ppb.peminta} />
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap">{ppb.keterangan ?? '—'}</div>
          </div>
          {ppb.catatan_baris && (
            <div className="sm:col-span-3">
              <div className="text-muted-foreground">Catatan Baris</div>
              <div className="whitespace-pre-wrap">{ppb.catatan_baris}</div>
            </div>
          )}
        </CardContent>
      </Card>

      {ppb.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(ppb.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}
    </div>
  )
}
