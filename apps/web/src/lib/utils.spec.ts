import { describe, expect, it } from 'vitest'
import { formatAlbumDateRangeLabel, formatDateLabel, formatTimelineMonthLabel } from './utils'

describe('formatDateLabel', () => {
  it('preserves the API calendar day in timezones behind UTC', () => {
    expect(formatDateLabel('2026-04-30T00:00:00.000Z', 'pt-BR')).toBe('30 de abril de 2026')
  })
})

describe('formatAlbumDateRangeLabel', () => {
  it('returns null without a start date', () => {
    expect(formatAlbumDateRangeLabel(null)).toBeNull()
  })

  it('formats a single day', () => {
    const label = formatAlbumDateRangeLabel('2024-06-01T00:00:00.000Z')
    expect(label).toContain('2024')
    expect(label).not.toContain('–')
  })

  it('formats a range with an en dash', () => {
    const label = formatAlbumDateRangeLabel(
      '2024-06-01T00:00:00.000Z',
      '2024-06-05T00:00:00.000Z',
    )
    expect(label).toContain('–')
  })

  it('treats same calendar day as a single date', () => {
    const label = formatAlbumDateRangeLabel(
      '2024-06-01T00:00:00.000Z',
      '2024-06-01T23:00:00.000Z',
    )
    expect(label).not.toContain('–')
  })
})

describe('formatTimelineMonthLabel', () => {
  it('formats month and year in UTC', () => {
    expect(formatTimelineMonthLabel(2024, 6, 'pt-BR')).toBe('junho de 2024')
  })
})
