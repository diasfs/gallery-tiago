import type { RouteLocationRaw } from 'vue-router'

const RESERVED_ALBUM_SLUGS = new Set([
  'search',
  'map',
  'timeline',
  'memories',
  'popular',
  'albums',
  'photos',
  'people',
  'tags',
  'locations',
  'admin',
  'api',
  'converted',
  'originals',
  'faces',
  'avatars',
])

export function albumPath(slug: string): RouteLocationRaw {
  return { name: 'album', params: { slug } }
}

export function photoPath(photo: { albumSlug: string; filename: string }): RouteLocationRaw {
  return {
    name: 'photo',
    params: { albumSlug: photo.albumSlug, filename: photo.filename },
  }
}

export function isReservedAlbumSlug(slug: string): boolean {
  return RESERVED_ALBUM_SLUGS.has(slug.toLowerCase())
}

export function albumHref(slug: string): string {
  return `/${encodeURIComponent(slug)}`
}

export function photoHref(photo: { albumSlug: string; filename: string }): string {
  const encodedFilename = photo.filename
    .split('/')
    .map((segment) => encodeURIComponent(segment))
    .join('/')

  return `/${encodeURIComponent(photo.albumSlug)}/${encodedFilename}`
}
