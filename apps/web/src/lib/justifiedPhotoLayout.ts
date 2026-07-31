import type { PhotoSummary } from '../api/types'

export interface JustifiedPhotoTile {
  photoId: string
  width: number
  height: number
}

function photoAspectRatio(photo: PhotoSummary): number {
  const width = photo.width ?? 0
  const height = photo.height ?? 0
  if (width > 0 && height > 0) {
    return width / height
  }

  return 1
}

export function computeJustifiedPhotoLayout(
  photos: PhotoSummary[],
  containerWidth: number,
  targetRowHeight: number,
  gap = 16,
): JustifiedPhotoTile[] {
  if (photos.length === 0 || containerWidth <= 0 || targetRowHeight <= 0) {
    return []
  }

  const rows: PhotoSummary[][] = []
  let currentRow: PhotoSummary[] = []
  let currentAspectSum = 0

  for (const photo of photos) {
    const aspect = photoAspectRatio(photo)
    const nextAspectSum = currentAspectSum + aspect
    const rowCount = currentRow.length + 1
    const totalGap = gap * Math.max(0, rowCount - 1)
    const rowWidthAtTarget = nextAspectSum * targetRowHeight + totalGap

    currentRow.push(photo)
    currentAspectSum = nextAspectSum

    if (rowWidthAtTarget >= containerWidth) {
      rows.push(currentRow)
      currentRow = []
      currentAspectSum = 0
    }
  }

  if (currentRow.length > 0) {
    rows.push(currentRow)
  }

  const tiles: JustifiedPhotoTile[] = []

  rows.forEach((row, rowIndex) => {
    const isLastRow = rowIndex === rows.length - 1
    const aspectSum = row.reduce((sum, photo) => sum + photoAspectRatio(photo), 0)
    const totalGap = gap * Math.max(0, row.length - 1)
    const availableWidth = containerWidth - totalGap
    const rowHeight =
      isLastRow && rows.length > 1
        ? targetRowHeight
        : availableWidth / aspectSum

    for (const photo of row) {
      const aspect = photoAspectRatio(photo)
      tiles.push({
        photoId: photo.id,
        width: aspect * rowHeight,
        height: rowHeight,
      })
    }
  })

  return tiles
}
