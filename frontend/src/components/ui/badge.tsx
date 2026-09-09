import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-primary/10 text-primary',
        neutral: 'border-transparent bg-secondary text-secondary-foreground',
        success: 'border-transparent bg-emerald-100 text-emerald-800',
        warning: 'border-transparent bg-amber-100 text-amber-800',
        danger: 'border-transparent bg-red-100 text-red-800',
      },
    },
    defaultVariants: { variant: 'default' },
  },
)

export function Badge({
  className,
  variant,
  ...props
}: HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}

export function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; variant: 'success' | 'danger' | 'neutral' }> = {
    AMAN: { label: 'Aman', variant: 'success' },
    TIDAK_AMAN: { label: 'Tidak Aman', variant: 'danger' },
    BEP: { label: 'BEP', variant: 'neutral' },
  }
  const s = map[status] ?? { label: status, variant: 'neutral' as const }
  return <Badge variant={s.variant}>{s.label}</Badge>
}

export function PriorityBadge({ level }: { level: string }) {
  const map: Record<string, 'danger' | 'warning' | 'neutral'> = {
    HIGH: 'danger',
    MEDIUM: 'warning',
    LOW: 'neutral',
  }
  return <Badge variant={map[level] ?? 'neutral'}>{level}</Badge>
}
