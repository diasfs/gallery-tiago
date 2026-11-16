import type { RouteLocationRaw } from 'vue-router'
import { api } from '../api/client'
import type { PublicSearchParams } from '../api/types'

export const SEARCH_NAV_QUERY = { nav: 'search' } as const
export const PHOTO_NAV_STORAGE_KEY = 'gallery:photoNav'
export const SEARCH_PHOTO_PER_PAGE = 48

export type PhotoNavEntry = {
  id: string
  albumSlug?: string
  filename?: string | null
}

export type PhotoNavContext = {
  kind: 'search'
  params: PublicSearchParams
  perPage: number
  total: number
  pages: Record<number, PhotoNavEntry[]>
}

export type SearchNeighbor =
  | { kind: 'photo'; entry: PhotoNavEntry }
  | { kind: 'end' }
  | { kind: 'unknown' }

type SearchCriteria = {
  q: string
  scope: string
  person: string
  tag: string
  year: string
  from: string
  to: string
}

function searchCriteria(params: PublicSearchParams): SearchCriteria {
  return {
    q: params.q ?? '',
    scope: params.scope ?? 'photos',
    person: [...(params.person ?? [])].sort().join(','),
    tag: [...(params.tag ?? [])].sort().join(','),
    year: params.year ?? '',
    from: params.from ?? '',
    to: params.to ?? '',
  }
}

function sameCriteria(a: PublicSearchParams, b: PublicSearchParams): boolean {
  return JSON.stringify(searchCriteria(a)) === JSON.stringify(searchCriteria(b))
}

function storedParams(params: PublicSearchParams): PublicSearchParams {
  const next: PublicSearchParams = {}
  if (params.q) next.q = params.q
  if (params.scope && params.scope !== 'photos') next.scope = params.scope
  if (params.person?.length) next.person = params.person
  if (params.tag?.length) next.tag = params.tag
  if (params.year) next.year = params.year
  if (params.from) next.from = params.from
  if (params.to) next.to = params.to
  return next
}

function toEntry(photo: PhotoNavEntry): PhotoNavEntry {
  return {
    id: photo.id,
    albumSlug: photo.albumSlug,
    filename: photo.filename,
  }
}

export function getPhotoNav(): PhotoNavContext | null {
  const raw = sessionStorage.getItem(PHOTO_NAV_STORAGE_KEY)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as PhotoNavContext
    if (parsed?.kind !== 'search' || !parsed.pages) return null
    return parsed
  } catch {
    return null
  }
}

export function setPhotoNav(context: PhotoNavContext): void {
  sessionStorage.setItem(PHOTO_NAV_STORAGE_KEY, JSON.stringify(context))
}

export function clearPhotoNav(): void {
  sessionStorage.removeItem(PHOTO_NAV_STORAGE_KEY)
}

export function writeSearchPage(
  params: PublicSearchParams,
  page: number,
  photos: PhotoNavEntry[],
  total: number,
  perPage: number = SEARCH_PHOTO_PER_PAGE,
): void {
  const entries = photos.map(toEntry)
  const existing = getPhotoNav()
  if (existing && sameCriteria(existing.params, params)) {
    setPhotoNav({
      ...existing,
      perPage,
      total,
      pages: { ...existing.pages, [page]: entries },
    })
    return
  }

  setPhotoNav({
    kind: 'search',
    params: storedParams(params),
    perPage,
    total,
    pages: { [page]: entries },
  })
}

function findPhoto(
  context: PhotoNavContext,
  photoId: string,
): { page: number; index: number; photos: PhotoNavEntry[] } | null {
  for (const [pageKey, photos] of Object.entries(context.pages)) {
    const index = photos.findIndex((photo) => photo.id === photoId)
    if (index >= 0) {
      return { page: Number(pageKey), index, photos }
    }
  }
  return null
}

async function loadSearchPage(context: PhotoNavContext, page: number): Promise<PhotoNavEntry[]> {
  const cached = context.pages[page]
  if (cached) return cached

  const result = await api.search({
    ...context.params,
    photoPage: page,
    photoPerPage: context.perPage,
  })
  writeSearchPage(context.params, page, result.data.photos, result.meta.photos.total, context.perPage)
  return getPhotoNav()?.pages[page] ?? result.data.photos.map(toEntry)
}

export async function resolveSearchNeighbor(
  photoId: string,
  direction: 'prev' | 'next',
): Promise<SearchNeighbor> {
  const context = getPhotoNav()
  if (!context) return { kind: 'unknown' }

  const location = findPhoto(context, photoId)
  if (!location) return { kind: 'unknown' }

  const { page, index, photos } = location
  const step = direction === 'prev' ? -1 : 1
  const nextIndex = index + step

  if (nextIndex >= 0 && nextIndex < photos.length) {
    return { kind: 'photo', entry: photos[nextIndex] }
  }

  const globalIndex = (page - 1) * context.perPage + index
  if (direction === 'prev' && globalIndex <= 0) return { kind: 'end' }
  if (direction === 'next' && globalIndex >= context.total - 1) return { kind: 'end' }

  const neighborPage = page + step
  if (neighborPage < 1) return { kind: 'end' }

  try {
    const neighborPhotos = await loadSearchPage(context, neighborPage)
    const entry = direction === 'next' ? neighborPhotos[0] : neighborPhotos[neighborPhotos.length - 1]
    return entry ? { kind: 'photo', entry } : { kind: 'end' }
  } catch {
    return { kind: 'end' }
  }
}

export function photoNavRoute(entry: PhotoNavEntry): RouteLocationRaw {
  if (entry.albumSlug && entry.filename) {
    return {
      name: 'photo',
      params: { albumSlug: entry.albumSlug, filename: entry.filename },
      query: SEARCH_NAV_QUERY,
    }
  }

  return { name: 'photo-legacy', params: { id: entry.id }, query: SEARCH_NAV_QUERY }
}
