export interface Location {
  id: string
  name: string
  city: string | null
  country: string | null
  latitude: number | null
  longitude: number | null
}

export interface GeocodeSuggestion {
  name: string
  city: string | null
  country: string | null
  latitude: number
  longitude: number
  displayName: string
}

export interface Tag {
  id: string
  name: string
  slug: string
  photoCount?: number
}

export interface AdminTag {
  id: string
  name: string
  slug: string
  photoCount: number
}

export type TagListSort = 'name' | 'slug' | 'recent'

export interface PersonSummary {
  id: string
  name: string | null
  avatarCropPath?: string | null
}

export interface CoverPhotoSummary {
  id: string
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
}

export interface AlbumSummary {
  id: string
  title: string
  slug: string
  description: string | null
  visibility: 'public' | 'unlisted' | 'private'
  sortOrder: number
  coverPhotoId: string | null
  coverPhoto?: CoverPhotoSummary | null
  parentSlug: string | null
  takenAt: string | null
  takenAtEnd?: string | null
  location: Location | null
  viewCount: number
}

export interface PhotoSummary {
  id: string
  albumId?: string
  title: string | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
  tags?: Tag[]
  viewCount: number
}

export interface AlbumDetail extends AlbumSummary {
  ancestors: Array<{ slug: string; title: string }>
  photosPerPage: number
}

export interface PageMeta {
  page: number
  perPage: number
  total: number
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface TimelineMonth {
  year: number
  month: number
  photoCount: number
}

export interface OnThisDayPhoto extends PhotoSummary {
  timelineAt: string
  yearsAgo: number
}

export interface OnThisDayMeta extends PageMeta {
  month: number
  day: number
  beforeYear: number
}

export interface MostViewedMeta extends PageMeta {
  period: 'all'
}

export interface PublicSearchParams {
  q?: string
  person?: string[]
  tag?: string[]
  year?: string
  from?: string
  to?: string
  albumPage?: number
  photoPage?: number
  albumPerPage?: number
  photoPerPage?: number
}

export interface PublicSearchResult {
  data: {
    albums: AlbumSummary[]
    photos: PhotoSummary[]
  }
  meta: {
    albums: PageMeta
    photos: PageMeta
  }
}

export interface PhotoDetail {
  id: string
  albumId: string
  albumSlug: string
  albumTitle: string
  albumAncestors: Array<{ slug: string; title: string }>
  title: string | null
  width: number | null
  height: number | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
  viewCount: number
  tags: Tag[]
  people: PersonSummary[]
  prevId: string | null
  nextId: string | null
}

export interface TagDetail {
  tag: Tag
}

export interface LocationDetail {
  location: Location
}

// --- Admin -----------------------------------------------------------------

export interface AdminUser {
  id: string
  email: string
  roles: string[]
}

export type MediaStatus = 'pending' | 'converting' | 'done' | 'failed'
export type FacesStatus = 'pending' | 'queued' | 'detecting' | 'done' | 'failed' | 'disabled'
export type TagsStatus = 'pending' | 'queued' | 'detecting' | 'done' | 'failed' | 'disabled'

export type TagDetector = 'ram_plus' | 'mobileclip_s0' | 'mobileclip_s1'

export interface ProcessingSettings {
  facesEnabled: boolean
  tagsEnabled: boolean
  tagDetector: TagDetector
}

export type ReprocessScope = 'all' | 'faces' | 'tags'

export type ProcessingStage = 'media' | 'faces' | 'tags'

export interface ProcessingSummary {
  media: Record<string, number>
  faces: Record<string, number>
  tags: Record<string, number>
}

export interface ProcessingPhotoRow {
  id: string
  title: string | null
  albumId: string
  albumTitle: string
  mediaStatus: MediaStatus
  facesStatus: FacesStatus
  tagsStatus: TagsStatus
  processingError: string | null
  hasOriginal: boolean
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
}

export interface ProcessingPhotosPage {
  data: ProcessingPhotoRow[]
  meta: { page: number; perPage: number; total: number }
}

export interface AdminAlbumCover {
  id: string
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath: string | null
}

export interface AdminAlbum {
  id: string
  title: string
  slug: string
  description: string | null
  visibility: 'public' | 'unlisted' | 'private'
  sortOrder: number
  photosPerPage: number
  coverPhotoId: string | null
  cover: AdminAlbumCover | null
  parentId: string | null
  childCount: number
  photoCount: number
  takenAt: string | null
  takenAtEnd: string | null
  location: Location | null
  createdAt: string
  updatedAt: string
}

/** Admin album show payload (children loaded via listAlbumChildren). */
export interface AdminAlbumDetail extends AdminAlbum {
  /** Immediate parent summary when nested; null for roots. */
  parent: { id: string; title: string } | null
}

export interface AdminPhotoSummary {
  id: string
  albumId: string
  title: string | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
  mediaStatus: MediaStatus
  facesStatus: FacesStatus
  tagsStatus: TagsStatus
  processingError: string | null
  sortOrder?: number
  createdAt: string
}

export interface AdminPhotoDetail {
  id: string
  albumId: string
  title: string | null
  width: number | null
  height: number | null
  avifPath: string | null
  thumbPaths: Record<string, string>
  originalPath?: string | null
  mediaStatus: MediaStatus
  facesStatus: FacesStatus
  tagsStatus: TagsStatus
  processingError: string | null
  tags: Tag[]
  people: PersonSummary[]
  createdAt: string
}

export interface Face {
  id: string
  photoId: string | null
  personId: string | null
  cropPath: string | null
  hasEmbedding: boolean
}

export interface UnnamedPersonCluster {
  id: string
  faceCount: number
  faces: Face[]
  avatarFaceId?: string | null
  avatarCropPath?: string | null
}

export interface AdminPerson {
  id: string
  name: string | null
  isNamed: boolean
  faceCount: number
  avatarFaceId: string | null
  avatarCropPath: string | null
}

export interface AdminPersonDetail extends AdminPerson {
  faces: Face[]
  hasCustomAvatar?: boolean
}

export type PeopleScope = 'all' | 'named' | 'unnamed'

export interface MergeSuggestion {
  sourcePersonId: string
  targetPersonId: string
  distance: number
  faceCountA: number
  faceCountB: number
  sourceAvatarCropPath: string | null
  targetAvatarCropPath: string | null
}

export interface MergeSuggestionsMeta {
  unnamedClusterCount: number
  analyzedClusterCount: number
  truncated: boolean
  durationMs: number
}

export interface MergeSuggestionsResponse {
  data: MergeSuggestion[]
  meta: MergeSuggestionsMeta
}

export interface FaceSearchMatch {
  personId: string
  isNamed: boolean
  distance: number
  name: string | null
  avatarCropPath: string | null
}
