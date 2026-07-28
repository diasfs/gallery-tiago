import { describe, expect, it } from 'vitest'
import { adminDeepLink } from './adminDeepLink'

describe('adminDeepLink', () => {
  it('maps photo detail to admin photo edit', () => {
    expect(adminDeepLink({ name: 'photo', params: { id: 'photo-1' } })).toEqual({
      name: 'admin-photo-edit',
      params: { id: 'photo-1' },
    })
  })

  it('maps person detail to admin person edit', () => {
    expect(adminDeepLink({ name: 'person', params: { id: 'person-1' } })).toEqual({
      name: 'admin-person-edit',
      params: { id: 'person-1' },
    })
  })

  it('maps album detail to admin album photos when album id is known', () => {
    expect(adminDeepLink({ name: 'album', params: { slug: 'summer' } }, 'album-uuid')).toEqual({
      name: 'admin-album-photos',
      params: { albumId: 'album-uuid' },
    })
  })

  it('falls back to admin albums when album id is unknown or route is unrelated', () => {
    expect(adminDeepLink({ name: 'album', params: { slug: 'summer' } })).toEqual({
      name: 'admin-albums',
    })
    expect(adminDeepLink({ name: 'home', params: {} })).toEqual({ name: 'admin-albums' })
    expect(adminDeepLink({ name: 'tag', params: { slug: 'beach' } })).toEqual({
      name: 'admin-albums',
    })
  })
})
