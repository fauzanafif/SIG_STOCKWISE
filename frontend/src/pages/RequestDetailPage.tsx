import { useEffect, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useRequest, useRequestAction, usePhysicalCheck, useSetRequestRefs } from '@/features/requests/api'
import { useCreateGoodsIssueFromRequest } from '@/features/goods-issues/api'
import { useCreatePurchaseProposalFromRequest } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { RequestLine } from '@/types/request'

export function RequestDetailPage() {
  const { id } = useParams()
  const requestId = Number(id)
  const { user, hasPermission } = useAuth()
  const navigate = useNavigate()
  const { data: req, isLoading } = useRequest(requestId)
  const action = useRequestAction(requestId)
  const check = usePhysicalCheck(requestId)
  const createGoodsIssue = useCreateGoodsIssueFromRequest()
  const createPpb = useCreatePurchaseProposalFromRequest()
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !req) return <p className="text-muted-foreground">Memuat…</p>

  const isOwner = req.requester.id === user?.id
  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{req.number}</h1>
          <p className="text-sm text-muted-foreground">{req.purpose}</p>
        </div>
        <RequestStatusBadge status={req.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-3">
          <Field
            label="Tanggal"
            value={req.request_date ? new Date(req.request_date).toLocaleDateString('id-ID') : '—'}
          />
          <Field label="Nama Peminta" value={req.requester_name ?? req.requester.name ?? '—'} />
          <Field label="WhatsApp" value={req.requester_wa ?? '—'} />
          <Field label="Site" value={req.site?.code ?? '—'} />
          <Field label="Lokasi permintaan" value={<NetworkTag req={req} canSeeIp={hasPermission('request.review')} />} />
          <Field label="Dibuat" value={new Date(req.created_at).toLocaleString('id-ID')} />
          <Field label="Dikirim" value={req.submitted_at ? new Date(req.submitted_at).toLocaleString('id-ID') : '—'} />
          <Field label="Direview" value={req.reviewed_at ? new Date(req.reviewed_at).toLocaleString('id-ID') : '—'} />
          <Field label="Reviewer" value={req.reviewer?.name ?? '—'} />
          <Field label="Selesai" value={req.completed_at ? new Date(req.completed_at).toLocaleString('id-ID') : '—'} />
          <div className="sm:col-span-3">
            <div className="text-muted-foreground">Keterangan</div>
            <div>{req.purpose || '—'}</div>
          </div>
          {req.notes && (
            <div className="sm:col-span-3">
              <div className="text-muted-foreground">Catatan</div>
              <div className="whitespace-pre-wrap">{req.notes}</div>
            </div>
          )}
          {req.cancel_reason && <Field label="Alasan batal" value={req.cancel_reason} />}
        </CardContent>
      </Card>

      <RefsCard
        requestId={requestId}
        npbgNo={req.npbg_no}
        ppbNo={req.ppb_no}
        canEdit={hasPermission('request.review')}
      />

      {/* actions */}
      <div className="flex flex-wrap gap-2">
        {req.status === 'DRAFT' && isOwner && (
          <Button size="sm" onClick={() => run('submit')}>Kirim</Button>
        )}
        {req.status === 'SUBMITTED' && hasPermission('request.review') && (
          <Button size="sm" onClick={() => run('review')}>Mulai Review</Button>
        )}
        {req.status === 'UNDER_REVIEW' && hasPermission('request.reserve') && (
          <Button size="sm" onClick={() => run('reserve')}>Reserve Stok</Button>
        )}
        {(req.status === 'RESERVED' || req.status === 'PARTIAL') && hasPermission('goods_issue.create') && (
          <Button
            size="sm"
            disabled={createGoodsIssue.isPending}
            onClick={() =>
              createGoodsIssue.mutate(requestId, {
                onSuccess: (goodsIssue) => navigate(`/goods-issues/${goodsIssue.id}`),
                onError: (e) => setErr(apiErrorMessage(e)),
              })
            }
          >
            Buat Bukti Keluar Barang
          </Button>
        )}
        {(req.status === 'PARTIAL' || req.status === 'NEED_PURCHASE') &&
          hasPermission('request.set_need_purchase') && (
            <Button size="sm" variant="outline" onClick={() => run('need-purchase')}>
              Tandai Perlu Pembelian
            </Button>
          )}
        {(req.status === 'PARTIAL' || req.status === 'NEED_PURCHASE') && hasPermission('purchase_proposal.create') && (
          <Button
            size="sm"
            variant="outline"
            disabled={createPpb.isPending}
            onClick={() =>
              createPpb.mutate(requestId, {
                onSuccess: (ppb) => navigate(`/purchase-proposals/${ppb.id}`),
                onError: (e) => setErr(apiErrorMessage(e)),
              })
            }
          >
            Buat Usulan Pembelian
          </Button>
        )}
        {!['PICKED_UP', 'COMPLETED', 'CANCELLED'].includes(req.status) &&
          (isOwner || hasPermission('request.cancel_any')) && (
            <Button
              size="sm"
              variant="destructive"
              onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}
            >
              Batalkan
            </Button>
          )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <Card>
        <CardHeader>
          <CardTitle>Barang</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <div className="divide-y">
            {req.items?.map((line) => (
              <LineRow
                key={line.id}
                line={line}
                canCheck={req.status === 'UNDER_REVIEW' && hasPermission('request.physical_check')}
                onCheck={(payload) =>
                  check.mutate({ lineId: line.id, ...payload }, { onError: (e) => setErr(apiErrorMessage(e)) })
                }
              />
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function Field({ label, value }: { label: string; value?: ReactNode }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value}</div>
    </div>
  )
}

function NetworkTag({
  req,
  canSeeIp,
}: {
  req: { network_label: string | null; network_label_text: string | null; request_ip: string | null }
  canSeeIp: boolean
}) {
  const variant =
    req.network_label === 'OFFICE' ? 'success' : req.network_label === 'EXTERNAL' ? 'warning' : 'neutral'
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <Badge variant={variant}>{req.network_label_text ?? 'Tidak Diketahui'}</Badge>
      {canSeeIp && req.request_ip && (
        <span className="font-mono text-xs text-muted-foreground">{req.request_ip}</span>
      )}
    </span>
  )
}

function RefsCard({
  requestId,
  npbgNo,
  ppbNo,
  canEdit,
}: {
  requestId: number
  npbgNo: string | null
  ppbNo: string | null
  canEdit: boolean
}) {
  const setRefs = useSetRequestRefs(requestId)
  const [npbg, setNpbg] = useState(npbgNo ?? '')
  const [ppb, setPpb] = useState(ppbNo ?? '')
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    setNpbg(npbgNo ?? '')
    setPpb(ppbNo ?? '')
  }, [npbgNo, ppbNo])

  const dirty = npbg !== (npbgNo ?? '') || ppb !== (ppbNo ?? '')

  if (!canEdit) {
    return (
      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm">
          <Field label="No Bukti Keluar Barang" value={npbgNo ?? 'Belum diisi Admin Gudang'} />
          <Field label="Nomor PPB" value={ppbNo ?? 'Belum diisi Admin Gudang'} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">No Bukti Keluar Barang &amp; Nomor PPB</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-xs text-muted-foreground">
          Diisi oleh Admin Gudang. Terisi otomatis saat Bukti Keluar Barang / PPB dibuat dari request ini, atau isi manual di sini.
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label>No Bukti Keluar Barang</Label>
            <Input value={npbg} onChange={(e) => setNpbg(e.target.value)} placeholder="mis. NA/25/IX/138" />
          </div>
          <div className="space-y-1.5">
            <Label>Nomor PPB</Label>
            <Input value={ppb} onChange={(e) => setPpb(e.target.value)} placeholder="mis. PPB/NA/25/IX/010" />
          </div>
        </div>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <Button
          size="sm"
          disabled={!dirty || setRefs.isPending}
          onClick={() =>
            setRefs.mutate(
              { npbg_no: npbg || null, ppb_no: ppb || null },
              { onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          {setRefs.isPending ? 'Menyimpan…' : 'Simpan'}
        </Button>
      </CardContent>
    </Card>
  )
}

function LineRow({
  line,
  canCheck,
  onCheck,
}: {
  line: RequestLine
  canCheck: boolean
  onCheck: (p: { status: 'VERIFIED_MATCH' | 'VERIFIED_MISMATCH'; qty?: number; note?: string }) => void
}) {
  const [note, setNote] = useState('')
  const [qty, setQty] = useState('')

  return (
    <div className="space-y-2 p-3 text-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <span className="font-mono text-xs text-muted-foreground">{line.item_code ?? '—'}</span>{' '}
          {line.description}
          <div className="text-xs text-muted-foreground">
            Minta {line.qty_requested} {line.unit ?? ''} · sistem{' '}
            {line.system_stock_snapshot ?? '?'} · projected {line.projected_stock ?? '?'} · reserved{' '}
            {line.qty_reserved} · beli {line.qty_to_purchase}
          </div>
        </div>
        <RequestStatusBadge status={line.line_status} />
      </div>

      {line.warning && <p className="text-xs text-amber-700">{line.warning}</p>}

      <div className="text-xs">
        Cek fisik: <span className="font-medium">{line.physical_check_status}</span>
        {line.physical_check_note ? ` — ${line.physical_check_note}` : ''}
      </div>

      {canCheck && (
        <div className="flex flex-wrap items-center gap-2">
          <Input
            type="number"
            placeholder="qty fisik"
            className="h-8 w-28"
            value={qty}
            onChange={(e) => setQty(e.target.value)}
          />
          <Input
            placeholder="catatan (wajib bila beda)"
            className="h-8 flex-1"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          <Button
            size="sm"
            variant="outline"
            onClick={() => onCheck({ status: 'VERIFIED_MATCH', qty: qty ? Number(qty) : undefined })}
          >
            Sesuai
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              onCheck({ status: 'VERIFIED_MISMATCH', qty: qty ? Number(qty) : undefined, note })
            }
          >
            Tidak sesuai
          </Button>
        </div>
      )}
    </div>
  )
}
