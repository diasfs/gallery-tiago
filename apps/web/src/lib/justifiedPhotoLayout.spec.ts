import { describe, expect, it } from 'vitest'
import type { PhotoSummary } from '../api/types'
import { computeJustifiedPhotoLayout } from './justifiedPhotoLayout'

function makePhoto(overrides: Partial<PhotoSummary> = {}): PhotoSummary {
  return {
    id: 'photo-1',
    title: 'Test',
    avifPath: null,
    thumbPaths: {},
    viewCount: 0,
    ...overrides,
  }
}

describe('computeJustifiedPhotoLayout', () => {
  it('returns empty array for empty photos', () => {
    expect(computeJustifiedPhotoLayout([], 800, 200)).toEqual([])
  })

  it('sizes landscape photos wider than portrait in the same row', () => {
    const photos = [
      makePhoto({ id: 'landscape', width: 1600, height: 900 }),
      makePhoto({ id: 'portrait', width: 900, height: 1600 }),
    ]

    const tiles = computeJustifiedPhotoLayout(photos, 800, 200, 0)
    const landscape = tiles.find((tile) => tile.photoId === 'landscape')
    const portrait = tiles.find((tile) => tile.photoId === 'portrait')

    expect(landscape).toBeDefined()
    expect(portrait).toBeDefined()
    expect(landscape!.width).toBeGreaterThan(portrait!.width)
    expect(landscape!.height).toBe(portrait!.height)
  })

  it('falls back to square aspect ratio when dimensions are missing', () => {
    const photos = [makePhoto({ id: 'square', width: null, height: null })]

    const tiles = computeJustifiedPhotoLayout(photos, 400, 200, 0)

    expect(tiles).toHaveLength(1)
    expect(tiles[0]?.photoId).toBe('square')
    expect(tiles[0]?.width).toBe(tiles[0]?.height)
  })
})
