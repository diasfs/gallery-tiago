import { afterEach, describe, expect, it } from 'vitest'
import { applyPageMeta } from './usePageMeta'

describe('usePageMeta', () => {
  afterEach(() => {
    applyPageMeta(null)
  })

  it('sets document title and Open Graph tags', () => {
    applyPageMeta({
      title: 'Beach · Gallery',
      description: 'Summer album',
      image: 'http://localhost:5173/media/thumb.avif',
    })

    expect(document.title).toBe('Beach · Gallery')
    expect(document.querySelector('meta[property="og:title"]')?.getAttribute('content')).toBe(
      'Beach · Gallery',
    )
    expect(document.querySelector('meta[property="og:description"]')?.getAttribute('content')).toBe(
      'Summer album',
    )
    expect(document.querySelector('meta[property="og:image"]')?.getAttribute('content')).toBe(
      'http://localhost:5173/media/thumb.avif',
    )
  })

  it('resets managed tags on clear', () => {
    applyPageMeta({ title: 'Temp · Gallery' })
    applyPageMeta(null)

    expect(document.title).toBe('Gallery')
    expect(document.querySelector('meta[property="og:title"]')).toBeNull()
  })
})
