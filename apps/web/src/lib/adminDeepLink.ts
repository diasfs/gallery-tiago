import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'

/**
 * Maps a public gallery route to the matching admin edit screen when possible.
 * Album needs the UUID (admin routes use id, public uses slug).
 */
export function adminDeepLink(
  route: Pick<RouteLocationNormalizedLoaded, 'name' | 'params'>,
  albumId?: string | null,
): RouteLocationRaw {
  switch (route.name) {
    case 'photo':
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
      if (albumId) {
        return { name: 'admin-album-photos', params: { albumId } }
      }
      break
  }

  return { name: 'admin-albums' }
}
