import { Lock } from 'lucide-react'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'

/** Shown in place of a feature that's off via a hardcoded flag (config/features.ts) — see NotificationBell for how other disabled states look. */
export function FeatureDisabledNotice({ feature }: { feature: string }) {
  return (
    <div className="space-y-6">
      <PageHeader title={feature} icon={<Lock className="size-5" />} />
      <Card>
        <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-base font-medium">Fitur ini belum diaktifkan</p>
          <p className="max-w-md text-sm text-muted-foreground">
            {feature} untuk sementara dinonaktifkan. Hanya developer aplikasi yang bisa mengaktifkan fitur ini
            kembali. Hubungi developer bila Anda membutuhkan fitur ini.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
