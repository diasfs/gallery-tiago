function canShareJpegFiles(): boolean {
  if (typeof navigator.canShare !== 'function' || typeof navigator.share !== 'function') {
    return false
  }
  try {
    const probe = new File([new Uint8Array(1)], 'photo.jpg', { type: 'image/jpeg' })
    return navigator.canShare({ files: [probe] })
  } catch {
    return false
  }
}

function isAbortError(err: unknown): boolean {
  return typeof err === 'object' && err !== null && 'name' in err && err.name === 'AbortError'
}

function fallbackDownload(url: string, filename: string): void {
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
}

export async function handleJpegDownloadClick(
  event: Pick<Event, 'preventDefault'>,
  url: string,
  filename: string,
): Promise<void> {
  if (!canShareJpegFiles()) {
    return
  }

  event.preventDefault()
  try {
    const response = await fetch(url)
    if (!response.ok) {
      throw new Error('JPEG download failed')
    }
    const blob = await response.blob()
    const file = new File([blob], filename, { type: 'image/jpeg' })
    await navigator.share({ files: [file] })
  } catch (err) {
    if (isAbortError(err)) {
      return
    }
    fallbackDownload(url, filename)
  }
}
