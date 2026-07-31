import type {
  AdminAlbum,
  AdminAlbumDetail,
  AdminPerson,
  AdminPersonDetail,
  AdminPhotoDetail,
  AdminPhotoSummary,
  AdminTag,
  AdminUser,
  AlbumDetail,
  AlbumSummary,
  Face,
  FaceSearchMatch,
  GeocodeSuggestion,
  Location,
  LocationDetail,
  MergeSuggestionsResponse,
  MostViewedMeta,
  OnThisDayMeta,
  OnThisDayPhoto,
  Paginated,
  PeopleScope,
  PersonSummary,
  PhotoDetail,
  PhotoSummary,
  ProcessingPhotosPage,
  ProcessingSettings,
  ProcessingStage,
  ProcessingSummary,
  PublicSearchParams,
  PublicSearchResult,
  ReprocessScope,
  Tag,
  TagDetail,
  TagListSort,
  TimelineMonth,
  UnnamedPersonCluster,
} from './types'

// Defaults to same-origin ('') so requests go through the Vite dev-server
// proxy (see vite.config.ts) and the admin session cookie is sent without
// requiring CORS. Only set VITE_API_BASE_URL to a cross-origin URL if the
// API is not reachable through a same-origin proxy.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''

export class ApiError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

async function readApiErrorMessage(response: Response, fallback: string): Promise<string> {
  try {
    const body = (await response.json()) as { message?: string; detail?: string; title?: string }
    if (typeof body.detail === 'string' && body.detail !== '') {
      return body.detail
    }
    if (typeof body.message === 'string' && body.message !== '') {
      return body.message
    }
    if (typeof body.title === 'string' && body.title !== '') {
      return body.title
    }
  } catch {
    // ignore invalid JSON bodies
  }

  return fallback
}

async function getJson<T>(path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`)
  if (!response.ok) {
    throw new ApiError(`Request to ${path} failed with status ${response.status}`, response.status)
  }
  const body = (await response.json()) as { data: T }
  return body.data
}

async function postJson<T>(path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'POST',
    credentials: 'include',
  })
  if (!response.ok) {
    throw new ApiError(`Request to ${path} failed with status ${response.status}`, response.status)
  }
  const body = (await response.json()) as { data: T }
  return body.data
}

async function getJsonPage<T, M extends PageMeta = PageMeta>(path: string): Promise<{ data: T[]; meta: M }> {
  const response = await fetch(`${API_BASE_URL}${path}`)
  if (!response.ok) {
    throw new ApiError(`Request to ${path} failed with status ${response.status}`, response.status)
  }
  return (await response.json()) as { data: T[]; meta: M }
}

function queryString(params: Record<string, string | number | undefined | null>): string {
  const q = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    q.set(key, String(value))
  }
  const qs = q.toString()
  return qs ? `?${qs}` : ''
}

/**
 * All admin requests send `credentials: 'include'` so the session cookie set
 * by `POST /api/admin/login` is attached on subsequent requests. This relies
 * on the frontend and API being same-origin (via the Vite dev-server proxy
 * in development, or a reverse proxy in production) — cross-origin requests
 * would additionally require CORS configured on the API to allow credentials
 * for the frontend's origin.
 */
async function adminRequest<T>(
  path: string,
  init?: { method?: string; body?: unknown; isForm?: boolean },
): Promise<T> {
  const method = init?.method ?? 'GET'
  const headers: Record<string, string> = {}
  let body: BodyInit | undefined

  if (init?.isForm) {
    body = init.body as FormData
  } else if (init?.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(init.body)
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body,
    credentials: 'include',
  })

  if (!response.ok) {
    const fallback = `Admin request to ${path} failed with status ${response.status}`
    throw new ApiError(await readApiErrorMessage(response, fallback), response.status)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const parsed = (await response.json()) as { data: T }
  return parsed.data
}

async function adminRequestRaw<T>(
  path: string,
  init?: { method?: string; body?: unknown },
): Promise<T> {
  const method = init?.method ?? 'GET'
  const headers: Record<string, string> = {}
  let body: BodyInit | undefined

  if (init?.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(init.body)
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body,
    credentials: 'include',
  })

  if (!response.ok) {
    const fallback = `Admin request to ${path} failed with status ${response.status}`
    throw new ApiError(await readApiErrorMessage(response, fallback), response.status)
  }

  return (await response.json()) as T
}

/** Resolves a media-relative path (e.g. an AVIF or thumb path) against the API host. */
export function mediaUrl(path: string | null | undefined): string | null {
  if (!path) {
    return null
  }
  if (/^https?:\/\//.test(path)) {
    return path
  }
  return `${API_BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`
}

/** Absolute URL for Open Graph and other off-page consumers. */
export function absoluteMediaUrl(path: string | null | undefined): string | null {
  const url = mediaUrl(path)
  if (!url) {
    return null
  }
  if (/^https?:\/\//.test(url)) {
    return url
  }
  if (typeof window !== 'undefined') {
    return new URL(url, window.location.origin).href
  }
  return url
}

type PhotoMedia = {
  thumbPaths?: Record<string, string> | null
  avifPath?: string | null
  originalPath?: string | null
  width?: number | null
}

/** Sorted ascending numeric thumb entries (`"320"` → 320). Named keys like `medium` are ignored. */
function numericThumbEntries(thumbs: Record<string, string>): Array<[number, string]> {
  return Object.entries(thumbs)
    .map(([key, path]) => [Number(key), path] as [number, string])
    .filter(([size, path]) => Number.isFinite(size) && size > 0 && !!path)
    .sort((a, b) => a[0] - b[0])
}

/** Prefer named/medium thumb, else largest numeric thumb, then AVIF master, then original. */
export function photoDisplayUrl(photo: PhotoMedia): string | null {
  const thumbs = photo.thumbPaths ?? {}
  const numeric = numericThumbEntries(thumbs)
  const largestNumeric = numeric.at(-1)?.[1]
  const thumb = thumbs.medium ?? thumbs.small ?? largestNumeric ?? Object.values(thumbs)[0]
  return mediaUrl(thumb ?? photo.avifPath ?? photo.originalPath ?? null)
}

/**
 * Responsive `srcset` from numeric thumb keys (e.g. 320, 1280) plus the AVIF
 * master when it is wider than the largest thumb.
 */
export function photoSrcSet(photo: PhotoMedia): string | null {
  const thumbs = photo.thumbPaths ?? {}
  const numeric = numericThumbEntries(thumbs)
  const parts: string[] = []
  const seen = new Set<string>()

  for (const [size, path] of numeric) {
    const url = mediaUrl(path)
    if (!url || seen.has(url)) {
      continue
    }
    seen.add(url)
    parts.push(`${url} ${size}w`)
  }

  const maxThumb = numeric.at(-1)?.[0] ?? 0
  if (photo.avifPath) {
    const url = mediaUrl(photo.avifPath)
    const masterWidth =
      typeof photo.width === 'number' && photo.width > maxThumb
        ? Math.round(photo.width)
        : null
    if (url && masterWidth && !seen.has(url)) {
      seen.add(url)
      parts.push(`${url} ${masterWidth}w`)
    }
  }

  return parts.length > 1 ? parts.join(', ') : null
}

/** Default sizes for the public photo detail layout (app max-width 1200px). */
export const PHOTO_DETAIL_SIZES = '(max-width: 1200px) 100vw, 1200px'


function searchQueryString(params: PublicSearchParams): string {
  const q = new URLSearchParams()
  if (params.q) q.set('q', params.q)
  for (const id of params.person ?? []) q.append('person', id)
  for (const slug of params.tag ?? []) q.append('tag', slug)
  if (params.year) q.set('year', params.year)
  if (params.from) q.set('from', params.from)
  if (params.to) q.set('to', params.to)
  if (params.albumPage && params.albumPage > 1) q.set('albumPage', String(params.albumPage))
  if (params.photoPage && params.photoPage > 1) q.set('photoPage', String(params.photoPage))
  if (params.albumPerPage) q.set('albumPerPage', String(params.albumPerPage))
  if (params.photoPerPage) q.set('photoPerPage', String(params.photoPerPage))
  const qs = q.toString()
  return qs ? `?${qs}` : ''
}

async function getSearchResult(path: string): Promise<PublicSearchResult> {
  const response = await fetch(`${API_BASE_URL}${path}`)
  if (!response.ok) {
    throw new ApiError(`Request to ${path} failed with status ${response.status}`, response.status)
  }
  return (await response.json()) as PublicSearchResult
}

export const api = {
  listAlbums: (params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<AlbumSummary>(`/api/albums${queryString(params)}`),
  listRecentAlbums: (params: { limit?: number } = {}) =>
    getJson<AlbumSummary[]>(`/api/albums/recent${queryString(params)}`),
  listAlbumsOnMap: () => getJson<AlbumSummary[]>('/api/albums/map'),
  getAlbum: (slug: string) => getJson<AlbumDetail>(`/api/albums/${encodeURIComponent(slug)}`),
  recordAlbumView: (slug: string) =>
    postJson<{ viewCount: number }>(`/api/albums/${encodeURIComponent(slug)}/view`),
  listAlbumChildren: (slug: string, params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<AlbumSummary>(`/api/albums/${encodeURIComponent(slug)}/children${queryString(params)}`),
  listAlbumPhotos: (slug: string, params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<PhotoSummary>(`/api/albums/${encodeURIComponent(slug)}/photos${queryString(params)}`),
  getPhoto: (id: string) => getJson<PhotoDetail>(`/api/photos/${encodeURIComponent(id)}`),
  getPhotoByPath: (albumSlug: string, filename: string) =>
    getJson<PhotoDetail>(
      `/api/albums/${encodeURIComponent(albumSlug)}/photos/${encodeURIComponent(filename)}`,
    ),
  listSimilarPhotos: (id: string) =>
    getJson<PhotoSummary[]>(`/api/photos/${encodeURIComponent(id)}/similar`),
  recordPhotoView: (id: string) =>
    postJson<{ viewCount: number }>(`/api/photos/${encodeURIComponent(id)}/view`),
  search: (params: PublicSearchParams = {}) => getSearchResult(`/api/search${searchQueryString(params)}`),
  searchPeople: (q?: string) =>
    getJson<PersonSummary[]>(`/api/people${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  searchTags: (q?: string) => getJson<Tag[]>(`/api/tags${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  listTags: () => getJson<Tag[]>(`/api/tags?index=1`),
  getPerson: (id: string) => getJson<PersonSummary>(`/api/people/${encodeURIComponent(id)}`),
  getPersonPhotos: (id: string, params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<PhotoSummary>(`/api/people/${encodeURIComponent(id)}/photos${queryString(params)}`),
  getTag: (slug: string) => getJson<TagDetail>(`/api/tags/${encodeURIComponent(slug)}`),
  listTagPhotos: (slug: string, params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<PhotoSummary>(`/api/tags/${encodeURIComponent(slug)}/photos${queryString(params)}`),
  getLocation: (id: string) => getJson<LocationDetail>(`/api/locations/${encodeURIComponent(id)}`),
  listLocationPhotos: (id: string, params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<PhotoSummary>(
      `/api/locations/${encodeURIComponent(id)}/photos${queryString(params)}`,
    ),
  listTimelineMonths: () => getJson<TimelineMonth[]>('/api/timeline/months'),
  listTimelinePhotos: (
    params: { year: number; month: number; page?: number; perPage?: number },
  ) =>
    getJsonPage<PhotoSummary>(
      `/api/timeline/photos${queryString({
        year: params.year,
        month: params.month,
        page: params.page,
        perPage: params.perPage,
      })}`,
    ),
  listOnThisDayPhotos: (params: { month?: number; day?: number; beforeYear?: number; page?: number; perPage?: number } = {}) =>
    getJsonPage<OnThisDayPhoto, OnThisDayMeta>(`/api/discover/on-this-day${queryString(params)}`),
  listMostViewedPhotos: (params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<PhotoSummary, MostViewedMeta>(`/api/discover/most-viewed/photos${queryString(params)}`),
  listMostViewedAlbums: (params: { page?: number; perPage?: number } = {}) =>
    getJsonPage<AlbumSummary, MostViewedMeta>(`/api/discover/most-viewed/albums${queryString(params)}`),
}

export interface AlbumWritePayload {
  title?: string
  slug?: string
  description?: string | null
  visibility?: 'public' | 'unlisted' | 'private'
  sortOrder?: number
  photosPerPage?: number
  parentId?: string | null
  coverPhotoId?: string | null
  takenAt?: string | null
  takenAtEnd?: string | null
  locationId?: string | null
}

export interface PhotoWritePayload {
  title?: string | null
  tagIds?: string[]
}

export interface LocationWritePayload {
  name: string
  city?: string | null
  country?: string | null
  latitude?: number | null
  longitude?: number | null
}

export const adminApi = {
  login: (email: string, password: string) =>
    adminRequest<AdminUser>('/api/admin/login', { method: 'POST', body: { email, password } }),
  logout: () => adminRequest<void>('/api/admin/logout', { method: 'POST' }),
  me: () => adminRequest<AdminUser>('/api/admin/me'),

  listAlbums: (params: {
    page?: number
    perPage?: number
    visibility?: string
    q?: string
    from?: string
    to?: string
    location?: string
  } = {}) =>
    adminRequestRaw<Paginated<AdminAlbum>>(`/api/admin/albums${queryString(params)}`),
  listAlbumParentOptions: (params: { q?: string; exclude?: string; page?: number; perPage?: number } = {}) =>
    adminRequestRaw<Paginated<{ id: string; title: string; parentId: string | null }>>(
      `/api/admin/albums/parent-options${queryString(params)}`,
    ),
  getAlbum: (id: string) => adminRequest<AdminAlbumDetail>(`/api/admin/albums/${encodeURIComponent(id)}`),
  listAlbumChildren: (albumId: string, params: { page?: number; perPage?: number } = {}) =>
    adminRequestRaw<Paginated<AdminAlbum>>(
      `/api/admin/albums/${encodeURIComponent(albumId)}/children${queryString(params)}`,
    ),
  createAlbum: (payload: AlbumWritePayload) =>
    adminRequest<AdminAlbum>('/api/admin/albums', { method: 'POST', body: payload }),
  updateAlbum: (id: string, payload: AlbumWritePayload) =>
    adminRequest<AdminAlbum>(`/api/admin/albums/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload }),
  deleteAlbum: (id: string) => adminRequest<void>(`/api/admin/albums/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  listAlbumPhotos: (albumId: string, params: { page?: number; perPage?: number } = {}) =>
    adminRequestRaw<Paginated<AdminPhotoSummary>>(
      `/api/admin/albums/${encodeURIComponent(albumId)}/photos${queryString(params)}`,
    ),
  reorderAlbumPhotos: (albumId: string, photoIds: string[]) =>
    adminRequest<AdminPhotoSummary[]>(`/api/admin/albums/${encodeURIComponent(albumId)}/photos/order`, {
      method: 'PUT',
      body: { photoIds },
    }),
  reorderAlbums: (albumIds: string[]) =>
    adminRequest<AdminAlbum[]>('/api/admin/albums/order', {
      method: 'PUT',
      body: { albumIds },
    }),
  uploadPhoto: (albumId: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return adminRequest<AdminPhotoSummary>(`/api/admin/albums/${encodeURIComponent(albumId)}/photos`, {
      method: 'POST',
      body: form,
      isForm: true,
    })
  },

  getPhoto: (id: string) => adminRequest<AdminPhotoDetail>(`/api/admin/photos/${encodeURIComponent(id)}`),
  updatePhoto: (id: string, payload: PhotoWritePayload) =>
    adminRequest<AdminPhotoDetail>(`/api/admin/photos/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload }),
  reprocessPhoto: (id: string, scope: ReprocessScope = 'all') =>
    adminRequest<AdminPhotoDetail>(`/api/admin/photos/${encodeURIComponent(id)}/reprocess`, {
      method: 'POST',
      body: { scope },
    }),
  reprocessAlbum: (albumId: string, scope: ReprocessScope = 'all') =>
    adminRequest<AdminPhotoSummary[]>(`/api/admin/albums/${encodeURIComponent(albumId)}/photos/reprocess`, {
      method: 'POST',
      body: { scope },
    }),
  deletePhoto: (id: string) =>
    adminRequest<void>(`/api/admin/photos/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  bulkDeletePhotos: (ids: string[]) =>
    adminRequest<void>('/api/admin/photos/bulk-delete', { method: 'POST', body: { ids } }),
  addPersonToPhoto: (photoId: string, payload: { personId?: string; name?: string }) =>
    adminRequest<Face>(`/api/admin/photos/${encodeURIComponent(photoId)}/people`, {
      method: 'POST',
      body: payload,
    }),
  removePersonFromPhoto: (photoId: string, personId: string) =>
    adminRequest<void>(
      `/api/admin/photos/${encodeURIComponent(photoId)}/people/${encodeURIComponent(personId)}`,
      { method: 'DELETE' },
    ),

  listUnnamedPeople: () => adminRequest<UnnamedPersonCluster[]>('/api/admin/people/unnamed'),
  listMergeSuggestions: () =>
    adminRequestRaw<MergeSuggestionsResponse>('/api/admin/people/merge-suggestions'),
  searchPeopleByFace: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return adminRequest<FaceSearchMatch[]>('/api/admin/people/search-by-face', {
      method: 'POST',
      body: form,
      isForm: true,
    })
  },
  listPeople: (
    params: {
      scope?: PeopleScope
      q?: string
      page?: number
      perPage?: number
    } = {},
  ) =>
    adminRequestRaw<Paginated<AdminPerson>>(
      `/api/admin/people${queryString({
        scope: params.scope ?? 'named',
        q: params.q,
        page: params.page,
        perPage: params.perPage,
      })}`,
    ),
  getPerson: (id: string) => adminRequest<AdminPersonDetail>(`/api/admin/people/${encodeURIComponent(id)}`),
  updatePerson: (id: string, payload: { name?: string | null; avatarFaceId?: string | null }) =>
    adminRequest<AdminPersonDetail>(`/api/admin/people/${encodeURIComponent(id)}`, {
      method: 'PATCH',
      body: payload,
    }),
  uploadPersonAvatar: (id: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return adminRequest<AdminPersonDetail>(`/api/admin/people/${encodeURIComponent(id)}/avatar`, {
      method: 'POST',
      body: form,
      isForm: true,
    })
  },
  deletePersonAvatar: (id: string) =>
    adminRequest<AdminPersonDetail>(`/api/admin/people/${encodeURIComponent(id)}/avatar`, {
      method: 'DELETE',
    }),
  namePerson: (id: string, name: string) =>
    adminRequest<AdminPerson>(`/api/admin/people/${encodeURIComponent(id)}/name`, { method: 'POST', body: { name } }),
  mergePerson: (id: string, targetPersonId: string) =>
    adminRequest<AdminPerson>(`/api/admin/people/${encodeURIComponent(id)}/merge`, {
      method: 'POST',
      body: { targetPersonId },
    }),
  discardPerson: (id: string) => adminRequest<void>(`/api/admin/people/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  searchLocations: (q?: string) =>
    adminRequest<Location[]>(`/api/admin/locations${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  createLocation: (payload: LocationWritePayload) =>
    adminRequest<Location>('/api/admin/locations', { method: 'POST', body: payload }),
  geocodeSearch: (q: string) =>
    adminRequest<GeocodeSuggestion[]>(`/api/admin/geocode?q=${encodeURIComponent(q)}`),
  geocodeReverse: (lat: number, lon: number) =>
    adminRequest<GeocodeSuggestion>(
      `/api/admin/geocode/reverse?lat=${encodeURIComponent(String(lat))}&lon=${encodeURIComponent(String(lon))}`,
    ),
  searchTags: (
    params: { q?: string; page?: number; perPage?: number; sort?: TagListSort } = {},
  ) =>
    adminRequestRaw<Paginated<AdminTag>>(
      `/api/admin/tags${queryString({
        q: params.q,
        page: params.page,
        perPage: params.perPage,
        sort: params.sort,
      })}`,
    ),
  createTag: (name: string) => adminRequest<AdminTag>('/api/admin/tags', { method: 'POST', body: { name } }),
  updateTag: (id: string, name: string) =>
    adminRequest<AdminTag>(`/api/admin/tags/${encodeURIComponent(id)}`, { method: 'PATCH', body: { name } }),
  deleteTag: (id: string) =>
    adminRequest<void>(`/api/admin/tags/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  listUsers: () => adminRequest<AdminUser[]>('/api/admin/users'),
  createUser: (payload: { email: string; password: string }) =>
    adminRequest<AdminUser>('/api/admin/users', { method: 'POST', body: payload }),
  updateUser: (id: string, payload: { email?: string; password?: string }) =>
    adminRequest<AdminUser>(`/api/admin/users/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload }),
  deleteUser: (id: string) =>
    adminRequest<void>(`/api/admin/users/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  processingSummary: () => adminRequest<ProcessingSummary>('/api/admin/processing/summary'),
  processingPhotos: (params: {
    stage?: ProcessingStage
    status?: string
    page?: number
    perPage?: number
  }) => {
    const q = new URLSearchParams()
    if (params.stage) q.set('stage', params.stage)
    if (params.status) q.set('status', params.status)
    if (params.page) q.set('page', String(params.page))
    if (params.perPage) q.set('perPage', String(params.perPage))
    const qs = q.toString()
    return adminRequestRaw<ProcessingPhotosPage>(`/api/admin/processing/photos${qs ? `?${qs}` : ''}`)
  },
  processingReprocess: (photoIds: string[], scope: ReprocessScope = 'all') =>
    adminRequest<{ processed: number; skipped: number }>('/api/admin/processing/reprocess', {
      method: 'POST',
      body: { photoIds, scope },
    }),
  processingEnqueueConvert: (body: { photoIds: string[] } | { allPendingWithOriginal: true }) =>
    adminRequest<{ enqueued: number; remaining: number }>('/api/admin/processing/enqueue-convert', {
      method: 'POST',
      body,
    }),

  getSettings: () => adminRequest<ProcessingSettings>('/api/admin/settings'),
  updateSettings: (payload: Partial<ProcessingSettings>) =>
    adminRequest<ProcessingSettings>('/api/admin/settings', { method: 'PUT', body: payload }),
}
