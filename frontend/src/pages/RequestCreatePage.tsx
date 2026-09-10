import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ClipboardList, Trash2 } from 'lucide-react'
import { api, apiErrorMessage } from '@/lib/api'
import { useCreateRequest, useUnits } from '@/features/requests/api'
import { useAuth } from '@/auth/AuthContext'
import type { ItemLookupResult } from '@/features/inventory/api'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'

interface DraftLine {
  key: string
  item_id: number | null
  code: string | null
  description: string
  qty: number
  unit_id: number | null
  unit_locked: boolean // true when the UOM comes from the item master
}

const today = () => new Date().toISOString().slice(0, 10)

export function RequestCreatePage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const create = useCreateRequest()
  const { data: units } = useUnits()

  const [requestDate, setRequestDate] = useState(today())
  const [requesterName, setRequesterName] = useState(user?.name ?? '')
  const [requesterWa, setRequesterWa] = useState(user?.phone ?? '')
  const [keterangan, setKeterangan] = useState('')
  const [catatan, setCatatan] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [error, setError] = useState<string | null>(null)

  function addItem(item: ItemLookupResult) {
    setLines((ls) =>
      ls.some((l) => l.item_id === item.id)
        ? ls
        : [
            ...ls,
            {
              key: crypto.randomUUID(),
              item_id: item.id,
              code: item.code,
              description: item.description,
              qty: 1,
              unit_id: item.unit_id,
              unit_locked: item.unit_id != null,
            },
          ],
    )
  }

  function addFreeText() {
    setLines((ls) => [
      ...ls,
      { key: crypto.randomUUID(), item_id: null, code: null, description: '', qty: 1, unit_id: null, unit_locked: false },
    ])
  }

  function patch(key: string, next: Partial<DraftLine>) {
    setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...next } : l)))
  }

  function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    if (lines.length === 0) return setError('Tambahkan minimal 1 barang.')
    if (lines.some((l) => !l.description.trim())) return setError('Nama barang tidak boleh kosong.')
    if (lines.some((l) => !(l.qty > 0))) return setError('QTY harus lebih dari 0.')

    create.mutate(
      {
        purpose: keterangan,
        notes: catatan || undefined,
        requester_name: requesterName || undefined,
        requester_wa: requesterWa || undefined,
        request_date: requestDate || undefined,
        items: lines.map((l) => ({
          item_id: l.item_id,
          description_raw: l.description,
          qty_requested: l.qty,
          unit_id: l.unit_id ?? undefined,
        })),
      },
      {
        onSuccess: async (req) => {
          try {
            await api.post(`/api/requests/${req.id}/submit`)
          } catch {
            /* tetap draft bila submit gagal */
          }
          navigate(`/requests/${req.id}`)
        },
        onError: (err) => setError(apiErrorMessage(err)),
      },
    )
  }

  return (
    <form onSubmit={submit} className="space-y-5">
      <PageHeader title="Buat Request Barang" icon={<ClipboardList className="size-5" />} />

      <Card>
        <CardContent className="grid gap-4 p-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="tgl">Tanggal</Label>
            <Input id="tgl" type="date" value={requestDate} onChange={(e) => setRequestDate(e.target.value)} required />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="peminta">Nama Peminta</Label>
            <Input
              id="peminta"
              value={requesterName}
              onChange={(e) => setRequesterName(e.target.value)}
              placeholder="Nama orang yang meminta barang"
              required
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="wa">WhatsApp Peminta</Label>
            <Input
              id="wa"
              type="tel"
              value={requesterWa}
              onChange={(e) => setRequesterWa(e.target.value)}
              placeholder="08xxxxxxxxxx"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ket">Keterangan</Label>
            <Input
              id="ket"
              value={keterangan}
              onChange={(e) => setKeterangan(e.target.value)}
              placeholder="Keperluan / tujuan permintaan"
              required
            />
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="catatan">Catatan</Label>
            <Textarea
              id="catatan"
              value={catatan}
              onChange={(e) => setCatatan(e.target.value)}
              placeholder="Catatan tambahan (opsional)"
            />
          </div>
          <p className="text-xs text-muted-foreground sm:col-span-2">
            Lokasi permintaan (jaringan kantor / luar) tercatat otomatis dari alamat IP saat request dikirim.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <CardTitle className="text-base">Daftar Barang</CardTitle>
          <Button type="button" size="sm" variant="outline" onClick={addFreeText}>
            + Baris manual
          </Button>
        </CardHeader>
        <CardContent className="space-y-3 p-4 pt-0">
          <ItemPicker onPick={addItem} />

          {lines.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-2 font-medium">Nama Barang</th>
                    <th className="w-24 py-2 px-2 font-medium">QTY</th>
                    <th className="w-40 py-2 px-2 font-medium">UOM</th>
                    <th className="w-10" />
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {lines.map((l) => (
                    <tr key={l.key}>
                      <td className="py-2 pr-2">
                        {l.item_id ? (
                          <span>
                            <span className="font-mono text-xs text-muted-foreground">{l.code}</span>{' '}
                            {l.description}
                          </span>
                        ) : (
                          <Input
                            className="h-8"
                            placeholder="Tulis nama barang…"
                            value={l.description}
                            onChange={(e) => patch(l.key, { description: e.target.value })}
                          />
                        )}
                      </td>
                      <td className="py-2 px-2">
                        <Input
                          type="number"
                          min={0}
                          step="any"
                          className="h-8"
                          value={l.qty}
                          onChange={(e) => patch(l.key, { qty: Number(e.target.value) })}
                        />
                      </td>
                      <td className="py-2 px-2">
                        {l.unit_locked ? (
                          <span className="text-muted-foreground">
                            {units?.find((u) => u.id === l.unit_id)?.code ?? '—'}
                          </span>
                        ) : (
                          <Select
                            className="h-8"
                            value={l.unit_id ?? ''}
                            onChange={(e) => patch(l.key, { unit_id: e.target.value ? Number(e.target.value) : null })}
                          >
                            <option value="">— pilih —</option>
                            {units?.map((u) => (
                              <option key={u.id} value={u.id}>
                                {u.code}
                              </option>
                            ))}
                          </Select>
                        )}
                      </td>
                      <td className="py-2 text-right">
                        <button
                          type="button"
                          className="text-muted-foreground hover:text-destructive"
                          onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}
                          aria-label="Hapus baris"
                        >
                          <Trash2 className="size-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <p className="text-xs text-muted-foreground">
            No NPBG dan Nomor PPB tidak diisi di sini — diisi oleh Admin Gudang setelah request diproses.
          </p>
        </CardContent>
      </Card>

      {error && <p className="text-sm text-destructive">{error}</p>}

      <div className="flex gap-2">
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? 'Menyimpan…' : 'Kirim Request'}
        </Button>
        <Button type="button" variant="outline" onClick={() => navigate('/requests')}>
          Batal
        </Button>
      </div>
    </form>
  )
}
