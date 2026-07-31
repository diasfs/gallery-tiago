import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'

export interface AdminDeepLinkContext {
  albumId?: string | null
  photoId?: string | null
}

/**
 * Maps a public gallery route to the matching admin edit screen when possible.
 * Album needs the UUID (admin routes use id, public uses slug).
 */
export function adminDeepLink(
  route: Pick<RouteLocationNormalizedLoaded, 'name' | 'params'>,
  context: AdminDeepLinkContext = {},
): RouteLocationRaw {
  switch (route.name) {
    case 'photo':
    case 'photo-legacy':
      if (context.photoId) {
        return { name: 'admin-photo-edit', params: { id: context.photoId } }
      }
      if (typeof route.params.id === 'string') {
        return { name: 'admin-photo-edit', params: { id: route.params.id } }
      }
      break
    case 'person':
      if (typeof route.params.id === 'string') {
        return { name: 'admin-person-edit', params: { id: route.params.id } }
      }
      break
    case 'album':
    case 'album-legacy':
      if (context.albumId) {
        return { name: 'admin-album-photos', params: { albumId: context.albumId } }
      }
      break
  }

  return { name: 'admin-albums' }
}
