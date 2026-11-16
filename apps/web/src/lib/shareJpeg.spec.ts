import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { handleJpegDownloadClick } from './shareJpeg'

function stubShare(canShareFiles: boolean, share: () => Promise<void> = async () => {}) {
  vi.stubGlobal('navigator', {
    ...navigator,
    canShare: vi.fn((data?: ShareData) => Boolean(data?.files?.length) && canShareFiles),
    share: vi.fn(share),
  })
}

describe('handleJpegDownloadClick', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        blob: async () => new Blob(['jpeg'], { type: 'image/jpeg' }),
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('lets the native download proceed when files cannot be shared', async () => {
    stubShare(false)
    const event = { preventDefault: vi.fn() }

    await handleJpegDownloadClick(event, '/api/photos/p1/download.jpg', 'Beach.jpg')

    expect(event.preventDefault).not.toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()
    expect(navigator.share).not.toHaveBeenCalled()
  })

  it('shares a JPEG file and blocks the native download', async () => {
    stubShare(true)
    const event = { preventDefault: vi.fn() }

    await handleJpegDownloadClick(event, '/api/photos/p1/download.jpg', 'Beach.jpg')

    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(fetch).toHaveBeenCalledWith('/api/photos/p1/download.jpg')
    expect(navigator.share).toHaveBeenCalledOnce()
    const shared = vi.mocked(navigator.share).mock.calls[0][0]
    expect(shared.files).toHaveLength(1)
    expect(shared.files?.[0]).toMatchObject({ name: 'Beach.jpg', type: 'image/jpeg' })
  })

  it('does not fall back to download when the user cancels the share sheet', async () => {
    const abort = new DOMException('Share canceled', 'AbortError')
    stubShare(true, async () => {
      throw abort
    })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const event = { preventDefault: vi.fn() }

    await handleJpegDownloadClick(event, '/api/photos/p1/download.jpg', 'Beach.jpg')

    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(click).not.toHaveBeenCalled()
  })

  it('falls back to a file download when fetch fails', async () => {
    stubShare(true)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, blob: async () => new Blob() }))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const event = { preventDefault: vi.fn() }

    await handleJpegDownloadClick(event, '/api/photos/p1/download.jpg', 'Beach.jpg')

    expect(click).toHaveBeenCalledOnce()
    const anchor = click.mock.instances[0] as HTMLAnchorElement
    expect(anchor.getAttribute('href')).toBe('/api/photos/p1/download.jpg')
    expect(anchor.download).toBe('Beach.jpg')
  })
})
