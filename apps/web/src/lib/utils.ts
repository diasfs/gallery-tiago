import type { ClassValue } from 'clsx'
import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** YYYY-MM-DD for `<input type="date">` from an ISO datetime string. */
export function toDateInputValue(iso: string): string {
  return iso.slice(0, 10)
}

/** Long date label without time. */
export function formatDateLabel(iso: string, locale = 'pt-BR'): string {
  return new Date(iso).toLocaleDateString(locale, { dateStyle: 'long', timeZone: 'UTC' })
}

/** Single day or "start – end" range label. */
export function formatAlbumDateRangeLabel(
  takenAt: string | null | undefined,
  takenAtEnd?: string | null,
  locale = 'pt-BR',
): string | null {
  if (!takenAt) {
    return null
  }
  const start = formatDateLabel(takenAt, locale)
  if (!takenAtEnd || takenAtEnd.slice(0, 10) === takenAt.slice(0, 10)) {
    return start
  }
  return `${start} – ${formatDateLabel(takenAtEnd, locale)}`
}

/** Month heading for the public timeline (e.g. "abril de 2024"). */
export function formatTimelineMonthLabel(
  year: number,
  month: number,
  locale = 'pt-BR',
): string {
  return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString(locale, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  })
}
