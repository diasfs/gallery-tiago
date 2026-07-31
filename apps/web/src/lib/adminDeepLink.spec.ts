import { describe, expect, it } from 'vitest'
import { adminDeepLink } from './adminDeepLink'

describe('adminDeepLink', () => {
  it('maps legacy photo route to admin photo edit', () => {
    expect(adminDeepLink({ name: 'photo-legacy', params: { id: 'photo-1' } })).toEqual({
      name: 'admin-photo-edit',
      params: { id: 'photo-1' },
    })
  })

  it('maps root photo route using loaded photo id', () => {
    expect(
      adminDeepLink({ name: 'photo', params: { albumSlug: 'summer', filename: 'a.jpg' } }, { photoId: 'photo-1' }),
    ).toEqual({
      name: 'admin-photo-edit',
      params: { id: 'photo-1' },
    })
  })

  it('maps person route to admin person edit', () => {
    expect(adminDeepLink({ name: 'person', params: { id: 'person-1' } })).toEqual({
      name: 'admin-person-edit',
      params: { id: 'person-1' },
    })
  })

  it('maps album route to admin album photos when album id is known', () => {
    expect(adminDeepLink({ name: 'album', params: { slug: 'summer' } }, { albumId: 'album-uuid' })).toEqual({
      name: 'admin-album-photos',
      params: { albumId: 'album-uuid' },
    })
    expect(adminDeepLink({ name: 'album-legacy', params: { slug: 'summer' } }, { albumId: 'album-uuid' })).toEqual({
      name: 'admin-album-photos',
      params: { albumId: 'album-uuid' },
    })
  })

  it('falls back to admin albums list', () => {
    expect(adminDeepLink({ name: 'album', params: { slug: 'summer' } })).toEqual({ name: 'admin-albums' })
    expect(adminDeepLink({ name: 'home', params: {} })).toEqual({ name: 'admin-albums' })
    expect(adminDeepLink({ name: 'tag', params: { slug: 'beach' } })).toEqual({
      name: 'admin-albums',
    })
  })
})
