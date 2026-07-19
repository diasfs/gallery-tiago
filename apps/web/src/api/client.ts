import type {
  AdminAlbum,
  AdminPerson,
  AdminPhotoDetail,
  AdminPhotoSummary,
  AdminUser,
  AlbumDetail,
  AlbumSummary,
  Face,
  Location,
  LocationDetail,
  PersonSummary,
  PhotoDetail,
  PhotoSummary,
  Tag,
  TagDetail,
  UnnamedPersonCluster,
} from './types'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080'

export class ApiError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

async function getJson<T>(path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`)
  if (!response.ok) {
    throw new ApiError(`Request to ${path} failed with status ${response.status}`, response.status)
  }
  const body = (await response.json()) as { data: T }
  return body.data
}

/**
 * All admin requests send `credentials: 'include'` so the session cookie set
 * by `POST /api/admin/login` is attached on subsequent requests (and CORS on
 * the API side must allow credentials for the configured frontend origin).
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
    throw new ApiError(`Admin request to ${path} failed with status ${response.status}`, response.status)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const parsed = (await response.json()) as { data: T }
  return parsed.data
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

export const api = {
  listAlbums: () => getJson<AlbumSummary[]>('/api/albums'),
  getAlbum: (slug: string) => getJson<AlbumDetail>(`/api/albums/${encodeURIComponent(slug)}`),
  getPhoto: (id: string) => getJson<PhotoDetail>(`/api/photos/${encodeURIComponent(id)}`),
  getPerson: (id: string) => getJson<PersonSummary>(`/api/people/${encodeURIComponent(id)}`),
  getPersonPhotos: (id: string) => getJson<PhotoSummary[]>(`/api/people/${encodeURIComponent(id)}/photos`),
  getTag: (slug: string) => getJson<TagDetail>(`/api/tags/${encodeURIComponent(slug)}`),
  getLocation: (id: string) => getJson<LocationDetail>(`/api/locations/${encodeURIComponent(id)}`),
}

export interface AlbumWritePayload {
  title?: string
  slug?: string
  description?: string | null
  visibility?: 'public' | 'unlisted' | 'private'
  sortOrder?: number
  parentId?: string | null
  coverPhotoId?: string | null
}

export interface PhotoWritePayload {
  title?: string | null
  takenAt?: string | null
  locationId?: string | null
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

  listAlbums: () => adminRequest<AdminAlbum[]>('/api/admin/albums'),
  createAlbum: (payload: AlbumWritePayload) =>
    adminRequest<AdminAlbum>('/api/admin/albums', { method: 'POST', body: payload }),
  updateAlbum: (id: string, payload: AlbumWritePayload) =>
    adminRequest<AdminAlbum>(`/api/admin/albums/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload }),
  deleteAlbum: (id: string) => adminRequest<void>(`/api/admin/albums/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  listAlbumPhotos: (albumId: string) =>
    adminRequest<AdminPhotoSummary[]>(`/api/admin/albums/${encodeURIComponent(albumId)}/photos`),
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
  reprocessPhoto: (id: string) =>
    adminRequest<AdminPhotoDetail>(`/api/admin/photos/${encodeURIComponent(id)}/reprocess`, { method: 'POST' }),
  addPersonToPhoto: (photoId: string, personId: string) =>
    adminRequest<Face>(`/api/admin/photos/${encodeURIComponent(photoId)}/people`, {
      method: 'POST',
      body: { personId },
    }),
  removePersonFromPhoto: (photoId: string, personId: string) =>
    adminRequest<void>(
      `/api/admin/photos/${encodeURIComponent(photoId)}/people/${encodeURIComponent(personId)}`,
      { method: 'DELETE' },
    ),

  listUnnamedPeople: () => adminRequest<UnnamedPersonCluster[]>('/api/admin/people/unnamed'),
  searchPeople: (q?: string) =>
    adminRequest<AdminPerson[]>(`/api/admin/people${q ? `?q=${encodeURIComponent(q)}` : ''}`),
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
  searchTags: (q?: string) => adminRequest<Tag[]>(`/api/admin/tags${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  createTag: (name: string) => adminRequest<Tag>('/api/admin/tags', { method: 'POST', body: { name } }),
}
