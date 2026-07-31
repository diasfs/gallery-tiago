import { describe, expect, it } from 'vitest'
import { SHARE_PREVIEW_PATH } from './share-preview-proxy'

describe('share-preview-proxy', () => {
  it('matches legacy and root public album/photo paths', () => {
    expect(SHARE_PREVIEW_PATH.test('/albums/summer')).toBe(true)
    expect(SHARE_PREVIEW_PATH.test('/photos/550e8400-e29b-41d4-a716-446655440000')).toBe(true)
    expect(SHARE_PREVIEW_PATH.test('/summer')).toBe(true)
    expect(SHARE_PREVIEW_PATH.test('/summer/DSC_0001.jpg')).toBe(true)
  })

  it('does not match reserved SPA paths or unrelated URLs', () => {
    expect(SHARE_PREVIEW_PATH.test('/search')).toBe(false)
    expect(SHARE_PREVIEW_PATH.test('/admin/login')).toBe(false)
    expect(SHARE_PREVIEW_PATH.test('/tags/beach')).toBe(false)
    expect(SHARE_PREVIEW_PATH.test('/api/albums/summer')).toBe(false)
  })
})
