import { describe, expect, it } from 'vitest'
import { photoDisplayUrl, photoSrcSet } from './client'

describe('photoDisplayUrl', () => {
  it('prefers medium thumb over avif and original', () => {
    expect(
      photoDisplayUrl({
        thumbPaths: { '320': 'thumbs/a-320.avif', medium: 'thumbs/a-med.avif' },
        avifPath: 'converted/a/master.avif',
        originalPath: 'originals/a/a.jpg',
      }),
    ).toMatch(/thumbs\/a-med\.avif$/)
  })

  it('prefers the largest numeric thumb when no named size exists', () => {
    expect(
      photoDisplayUrl({
        thumbPaths: { '320': 'thumbs/a-320.avif', '1280': 'thumbs/a-1280.avif' },
        avifPath: 'converted/a/master.avif',
        originalPath: null,
      }),
    ).toMatch(/thumbs\/a-1280\.avif$/)
  })

  it('falls back to avif when no thumbs', () => {
    expect(
      photoDisplayUrl({
        thumbPaths: {},
        avifPath: 'converted/a/master.avif',
        originalPath: 'originals/a/a.jpg',
      }),
    ).toMatch(/converted\/a\/master\.avif$/)
  })

  it('falls back to original when no avif or thumbs', () => {
    expect(
      photoDisplayUrl({
        thumbPaths: {},
        avifPath: null,
        originalPath: 'originals/a/a.jpg',
      }),
    ).toMatch(/originals\/a\/a\.jpg$/)
  })

  it('returns null when nothing is available', () => {
    expect(photoDisplayUrl({ thumbPaths: {}, avifPath: null, originalPath: null })).toBeNull()
  })
})

describe('photoSrcSet', () => {
  it('builds srcset from numeric thumbs and wider avif master', () => {
    const srcset = photoSrcSet({
      thumbPaths: { '320': 'thumbs/a-320.avif', '1280': 'thumbs/a-1280.avif' },
      avifPath: 'converted/a/master.avif',
      width: 4000,
    })

    expect(srcset).toMatch(/thumbs\/a-320\.avif 320w/)
    expect(srcset).toMatch(/thumbs\/a-1280\.avif 1280w/)
    expect(srcset).toMatch(/converted\/a\/master\.avif 4000w/)
  })

  it('omits avif master when it is not wider than the largest thumb', () => {
    const srcset = photoSrcSet({
      thumbPaths: { '320': 'thumbs/a-320.avif', '1280': 'thumbs/a-1280.avif' },
      avifPath: 'converted/a/master.avif',
      width: 800,
    })
    expect(srcset).toMatch(/thumbs\/a-320\.avif 320w/)
    expect(srcset).toMatch(/thumbs\/a-1280\.avif 1280w/)
    expect(srcset).not.toMatch(/master\.avif/)
  })

  it('returns null when there is only one candidate (no useful srcset)', () => {
    expect(
      photoSrcSet({
        thumbPaths: { medium: 'thumbs/a-med.avif' },
        avifPath: 'converted/a/master.avif',
        width: 2000,
      }),
    ).toBeNull()

    expect(
      photoSrcSet({
        thumbPaths: { '1280': 'thumbs/a-1280.avif' },
        avifPath: null,
        width: null,
      }),
    ).toBeNull()
  })
})
