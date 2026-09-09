import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, apiErrorMessage } from '@/lib/api'
import { useCreateRequest } from '@/features/requests/api'
import type { ItemLookupResult } from '@/features/inventory/api'
import { ItemPicker } from '@/components/ItemPicker'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

interface DraftLine {
  key: string
  item_id: number | null
  code: string | null
  description: string
  qty: number
}

export function RequestCreatePage() {
  const navigate = useNavigate()
  const create = useCreateRequest()

  const [purpose, setPurpose] = useState('')
  const [workLocation, setWorkLocation] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [error, setError] = useState<string | null>(null)

  function addItem(item: ItemLookupResult) {
    setLines((ls) =>
      ls.some((l) => l.item_id === item.id)
        ? ls
        : [...ls, { key: crypto.randomUUID(), item_id: item.id, code: item.code, description: item.description, qty: 1 }],
    )
  }

  function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    if (lines.length === 0) {
      setError('Tambahkan minimal 1 barang.')
      return
    }
    create.mutate(
      {
        purpose,
        work_location: workLocation || undefined,
        items: lines.map((l) => ({
          item_id: l.item_id,
          description_raw: l.description,
          qty_requested: l.qty,
        })),
      },
      {
        onSuccess: async (req) => {
          try {
            await api.post(`/api/requests/${req.id}/submit`)
          } catch {
            /* stays as draft */
          }
          navigate(`/requests/${req.id}`)
        },
        onError: (err) => setError(apiErrorMessage(err)),
      },
    )
  }

  return (
    <form onSubmit={submit} className="max-w-2xl space-y-4">
      <h1 className="text-xl font-semibold">Buat Request Barang</h1>

      <Card>
        <CardContent className="space-y-4 p-4">
          <div className="space-y-1.5">
            <Label htmlFor="purpose">Keperluan</Label>
            <Input id="purpose" required value={purpose} onChange={(e) => setPurpose(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loc">Lokasi pekerjaan</Label>
            <Input id="loc" value={workLocation} onChange={(e) => setWorkLocation(e.target.value)} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Barang</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3 p-4 pt-0">
          <ItemPicker onPick={addItem} />

          {lines.length > 0 && (
            <ul className="divide-y rounded-md border">
              {lines.map((l) => (
                <li key={l.key} className="flex items-center gap-3 px-3 py-2 text-sm">
                  <div className="flex-1">
                    <span className="font-mono text-xs text-muted-foreground">{l.code ?? '—'}</span>{' '}
                    {l.description}
                  </div>
                  <Input
                    type="number"
                    min={1}
                    className="w-24"
                    value={l.qty}
                    onChange={(e) =>
                      setLines((ls) =>
                        ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)),
                      )
                    }
                  />
                  <button
                    type="button"
                    className="text-muted-foreground hover:text-destructive"
                    onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}
                  >
                    Hapus
                  </button>
                </li>
              ))}
            </ul>
          )}
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
