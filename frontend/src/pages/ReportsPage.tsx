import { useState } from 'react'
import { Download, FileSpreadsheet, FileText, Sheet } from 'lucide-react'
import { api, apiErrorMessage } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'

interface Dataset {
  key: string
  label: string
  description: string
  permission: string
}

const DATASETS: Dataset[] = [
  { key: 'inventory', label: 'Inventory', description: 'Stok aktual, reserved & tersedia per gudang', permission: 'report.inventory' },
  { key: 'requests', label: 'Request Barang', description: 'Seluruh permintaan barang & statusnya', permission: 'report.request' },
  { key: 'npbg', label: 'NPBG', description: 'Nota pengeluaran barang gudang', permission: 'report.npbg' },
  { key: 'ppb', label: 'PPB', description: 'Permintaan pembelian barang', permission: 'report.ppb' },
  { key: 'stock-opnames', label: 'Stock Opname', description: 'Riwayat opname & selisih', permission: 'report.opname' },
  { key: 'stock-movements', label: 'Pergerakan Stok', description: 'Kartu stok / ledger lengkap', permission: 'report.stock_movement' },
]

const FORMATS = [
  { key: 'xlsx', label: 'Excel', icon: FileSpreadsheet, perm: 'export.excel' },
  { key: 'csv', label: 'CSV', icon: Sheet, perm: 'export.csv' },
  { key: 'pdf', label: 'PDF', icon: FileText, perm: 'export.pdf' },
] as const

export function ReportsPage() {
  const { hasPermission } = useAuth()
  const [busy, setBusy] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const datasets = DATASETS.filter((d) => hasPermission(d.permission))

  async function download(dataset: string, format: string) {
    setBusy(`${dataset}:${format}`)
    setErr(null)
    try {
      const res = await api.get(`/api/export/${dataset}`, { params: { format }, responseType: 'blob' })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const match = disposition.match(/filename="?([^"]+)"?/)
      const name = match?.[1] ?? `${dataset}.${format === 'pdf' ? 'html' : format}`
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Laporan & Export"
        subtitle="Unduh data STOCKWISE dalam format Excel, CSV, atau PDF"
        icon={<Download className="size-5" />}
      />

      {err && <p className="text-sm text-destructive">{err}</p>}
      <p className="text-xs text-muted-foreground">
        Format PDF diunduh sebagai halaman cetak — buka file lalu gunakan “Simpan sebagai PDF” di dialog cetak browser.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        {datasets.map((d) => (
          <Card key={d.key}>
            <CardContent className="space-y-3 p-4">
              <div>
                <div className="font-medium">{d.label}</div>
                <div className="text-sm text-muted-foreground">{d.description}</div>
              </div>
              <div className="flex flex-wrap gap-2">
                {FORMATS.filter((f) => hasPermission(f.perm)).map((f) => {
                  const Icon = f.icon
                  const id = `${d.key}:${f.key}`
                  return (
                    <Button
                      key={f.key}
                      size="sm"
                      variant="outline"
                      disabled={busy === id}
                      onClick={() => download(d.key, f.key)}
                    >
                      <Icon className="size-4" />
                      {busy === id ? 'Menyiapkan…' : f.label}
                    </Button>
                  )
                })}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {datasets.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Anda tidak memiliki akses laporan.
          </CardContent>
        </Card>
      )}
    </div>
  )
}
