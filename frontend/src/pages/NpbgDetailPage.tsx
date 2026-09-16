import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Pencil } from 'lucide-react'
import { useNpbg, useUpdateNpbg } from '@/features/npbg/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NpbgVerificationPanel } from '@/components/NpbgVerificationPanel'
import type { Npbg, NpbgEditableFields } from '@/features/npbg/api'

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

function EditForm({ npbg, onDone }: { npbg: Npbg; onDone: () => void }) {
  const update = useUpdateNpbg(npbg.id)
  const [form, setForm] = useState<NpbgEditableFields>({
    tipe_npbg: npbg.tipe_npbg,
    klasifikasi: npbg.klasifikasi,
    deskripsi: npbg.deskripsi,
    nama_proyek: npbg.nama_proyek,
    no_seri_nopol: npbg.no_seri_nopol,
    dikeluarkan_oleh: npbg.dikeluarkan_oleh,
  })
  const [err, setErr] = useState<string | null>(null)

  const set = (k: keyof NpbgEditableFields) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value || null }))

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <p className="text-xs text-muted-foreground">
          Hanya field berikut yang bisa diubah manual — field lain mengikuti data Accurate dan akan diperbarui saat Sync Accurate.
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Tipe NPBG</label>
            <Input value={form.tipe_npbg ?? ''} onChange={set('tipe_npbg')} placeholder="mis. PENJUALAN" />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Klasifikasi</label>
            <Input value={form.klasifikasi ?? ''} onChange={set('klasifikasi')} />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Nama Proyek</label>
            <Input value={form.nama_proyek ?? ''} onChange={set('nama_proyek')} />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">No Seri / Nopol</label>
            <Input value={form.no_seri_nopol ?? ''} onChange={set('no_seri_nopol')} />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs text-muted-foreground">Dikeluarkan Oleh</label>
            <Input value={form.dikeluarkan_oleh ?? ''} onChange={set('dikeluarkan_oleh')} />
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs text-muted-foreground">Deskripsi</label>
            <Input value={form.deskripsi ?? ''} onChange={set('deskripsi')} />
          </div>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="outline" onClick={onDone}>Batal</Button>
          <Button
            size="sm"
            disabled={update.isPending}
            onClick={() => update.mutate(form, { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })}
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
  const [editing, setEditing] = useState(false)

  useEffect(() => setEditing(false), [npbgId])

  if (isLoading || !npbg) return <p className="text-muted-foreground">Memuat…</p>
  if (editing) return <EditForm npbg={npbg} onDone={() => setEditing(false)} />

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{npbg.no_npbg ?? '—'}</h1>
          <p className="text-sm text-muted-foreground">{fmtDate(npbg.tgl_npbg)}</p>
        </div>
        {hasPermission('npbg.update') && (
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" /> Ubah
          </Button>
        )}
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Tgl NPBG" value={fmtDate(npbg.tgl_npbg)} />
          <Field label="Shipdate" value={fmtDate(npbg.shipdate)} />
          <Field label="Taxdate" value={fmtDate(npbg.taxdate)} />
          <Field label="Tipe NPBG" value={npbg.tipe_npbg} />
          <Field label="Klasifikasi" value={npbg.klasifikasi} />
          <Field label="Divisi" value={npbg.divisi} />
          <Field label="Pelanggan" value={npbg.pelanggan} />
          <Field label="Nama Proyek" value={npbg.nama_proyek} />
          <Field label="No Seri / Nopol" value={npbg.no_seri_nopol} />
          <Field label="Dikeluarkan Oleh" value={npbg.dikeluarkan_oleh} />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field label="Kode Barang" value={npbg.kode_barang} />
          <Field label="Deskripsi Barang" value={npbg.deskripsi_barang} />
          <Field label="Kuantitas" value={npbg.kuantitas != null ? `${npbg.kuantitas} ${npbg.satuan ?? ''}` : null} />
          <Field label="Peminta" value={npbg.peminta} />
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Deskripsi</div>
            <div>{npbg.deskripsi ?? '—'}</div>
          </div>
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div className="whitespace-pre-wrap">{npbg.keterangan ?? '—'}</div>
          </div>
        </CardContent>
      </Card>

      {npbg.accurate_synced_at && (
        <p className="text-xs text-muted-foreground">
          Terakhir sync dari Accurate: {new Date(npbg.accurate_synced_at).toLocaleString('id-ID')}
        </p>
      )}

      <NpbgVerificationPanel npbgId={npbg.id} />
    </div>
  )
}
