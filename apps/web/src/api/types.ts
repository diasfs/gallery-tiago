export interface Location {
  id: string
  name: string
  city: string | null
  country: string | null
  latitude: number | null
  longitude: number | null
}

export interface Tag {
  id: string
  name: string
  slug: string
}

export interface PersonSummary {
  id: string
  name: string | null
}

export interface AlbumSummary {
  id: string
  title: string
  slug: string
  description: string | null
  visibility: 'public' | 'unlisted' | 'private'
  sortOrder: number
  coverPhotoId: string | null
  parentSlug: string | null
}

export interface PhotoSummary {
  id: string
  albumId?: string
  title: string | null
  takenAt: string | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  location?: Location | null
  tags?: Tag[]
}

export interface AlbumDetail extends AlbumSummary {
  ancestors: Array<{ slug: string; title: string }>
  children: AlbumSummary[]
  photos: PhotoSummary[]
}

export interface PhotoDetail {
  id: string
  albumId: string
  albumSlug: string
  title: string | null
  takenAt: string | null
  width: number | null
  height: number | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  location: Location | null
  tags: Tag[]
  people: PersonSummary[]
  prevId: string | null
  nextId: string | null
}

export interface TagDetail {
  tag: Tag
  photos: PhotoSummary[]
}

export interface LocationDetail {
  location: Location
  photos: PhotoSummary[]
}

// --- Admin -----------------------------------------------------------------

export interface AdminUser {
  id: string
  email: string
  roles: string[]
}

export type ProcessingStatus = 'pending' | 'converting' | 'detecting' | 'done' | 'failed'

export interface AdminAlbum {
  id: string
  title: string
  slug: string
  description: string | null
  visibility: 'public' | 'unlisted' | 'private'
  sortOrder: number
  coverPhotoId: string | null
  parentId: string | null
  childCount: number
  photoCount: number
  createdAt: string
  updatedAt: string
}

export interface AdminPhotoSummary {
  id: string
  albumId: string
  title: string | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  processingStatus: ProcessingStatus
  processingError: string | null
  createdAt: string
}

export interface AdminPhotoDetail {
  id: string
  albumId: string
  title: string | null
  takenAt: string | null
  width: number | null
  height: number | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  processingStatus: ProcessingStatus
  processingError: string | null
  location: Location | null
  tags: Tag[]
  people: PersonSummary[]
  createdAt: string
}

export interface Face {
  id: string
  photoId: string
  personId: string | null
  cropPath: string | null
  hasEmbedding: boolean
}

export interface UnnamedPersonCluster {
  id: string
  faceCount: number
  faces: Face[]
}

export interface AdminPerson {
  id: string
  name: string | null
  isNamed: boolean
  faceCount: number
}
