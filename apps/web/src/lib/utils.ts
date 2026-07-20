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
export function formatDateLabel(iso: string, locale?: string): string {
  return new Date(iso).toLocaleDateString(locale, { dateStyle: 'long' })
}
