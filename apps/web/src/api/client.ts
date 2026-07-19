import type {
  AlbumDetail,
  AlbumSummary,
  LocationDetail,
  PersonSummary,
  PhotoDetail,
  PhotoSummary,
  TagDetail,
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
