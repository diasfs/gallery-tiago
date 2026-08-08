import { computed, ref } from 'vue'
import { api } from '../api/client'
import type { SiteConfig } from '../api/types'

const DEFAULT_CONFIG: SiteConfig = {
  albumPhotoLayout: 'grid',
  mostViewedHomeEnabled: true,
  mostViewedExcludeRootAlbums: false,
}

let cachedConfig: SiteConfig | null = null
let loadPromise: Promise<SiteConfig> | null = null

async function fetchSiteConfig(): Promise<SiteConfig> {
  try {
    return await api.getSiteConfig()
  } catch {
    return DEFAULT_CONFIG
  }
}

export function useSiteConfig() {
  const config = ref<SiteConfig>(cachedConfig ?? DEFAULT_CONFIG)
  const loading = ref(!cachedConfig)

  if (!cachedConfig && !loadPromise) {
    loadPromise = fetchSiteConfig().then((loaded) => {
      cachedConfig = loaded
      config.value = loaded
      loading.value = false
      return loaded
    })
  } else if (loadPromise) {
    void loadPromise.then((loaded) => {
      config.value = loaded
      loading.value = false
    })
  }

  const albumPhotoLayout = computed(() => config.value.albumPhotoLayout)
  const mostViewedHomeEnabled = computed(() => config.value.mostViewedHomeEnabled)

  return {
    config,
    loading,
    albumPhotoLayout,
    mostViewedHomeEnabled,
  }
}

/** Clears the in-memory cache (for tests). */
export function resetSiteConfigCache(): void {
  cachedConfig = null
  loadPromise = null
}
