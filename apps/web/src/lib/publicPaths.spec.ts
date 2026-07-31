import { describe, expect, it } from 'vitest'
import { albumHref, albumPath, photoHref, photoPath } from './publicPaths'

describe('publicPaths', () => {
  it('builds root album paths', () => {
    expect(albumPath('summer')).toEqual({ name: 'album', params: { slug: 'summer' } })
    expect(albumHref('summer')).toBe('/summer')
  })

  it('builds root photo paths', () => {
    expect(photoPath({ albumSlug: 'summer', filename: 'DSC_0001.jpg' })).toEqual({
      name: 'photo',
      params: { albumSlug: 'summer', filename: 'DSC_0001.jpg' },
    })
    expect(photoHref({ albumSlug: 'summer', filename: 'my photo.jpg' })).toBe('/summer/my%20photo.jpg')
  })
})
