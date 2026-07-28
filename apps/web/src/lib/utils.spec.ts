import { describe, expect, it } from 'vitest'
import { formatAlbumDateRangeLabel } from './utils'

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
