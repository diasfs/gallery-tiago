<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, photoDisplayUrl } from '../api/client'
import type { AlbumSummary } from '../api/types'
import AlbumClusterMap, { type AlbumMapMarker } from '../components/AlbumClusterMap.vue'

const albums = ref<AlbumSummary[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

function locationLabel(album: AlbumSummary): string | null {
  const loc = album.location
  if (!loc) return null
  const parts = [loc.name, loc.city, loc.country].filter((part): part is string => !!part?.trim())
  return parts.length > 0 ? parts.join(', ') : null
}

const markers = computed<AlbumMapMarker[]>(() => {
  const groups = new Map<string, AlbumMapMarker>()

  for (const album of albums.value) {
    const loc = album.location
    const lat = loc?.latitude
    const lng = loc?.longitude
    if (!loc || lat == null || lng == null) {
      continue
    }

    const key = loc.id
    const existing = groups.get(key)
    if (existing) {
      existing.albums.push({
        slug: album.slug,
        title: album.title,
        coverUrl: album.coverPhoto ? photoDisplayUrl(album.coverPhoto) : null,
      })
      continue
    }

    groups.set(key, {
      latitude: lat,
      longitude: lng,
      label: locationLabel(album),
      albums: [
        {
          slug: album.slug,
          title: album.title,
          coverUrl: album.coverPhoto ? photoDisplayUrl(album.coverPhoto) : null,
        },
      ],
    })
  }

  return [...groups.values()]
})

async function load() {
  loading.value = true
  error.value = null
  try {
    albums.value = await api.listAlbumsOnMap()
  } catch {
    albums.value = []
    error.value = 'Não foi possível carregar o mapa. Tente novamente mais tarde.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section>
    <h1>Mapa</h1>
    <p v-if="loading">Carregando mapa…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="markers.length === 0" data-testid="map-empty">
      Nenhum álbum público com localização ainda.
    </p>
    <AlbumClusterMap v-else :markers="markers" />
  </section>
</template>

<style scoped>
.error {
  color: var(--destructive, #c00);
}
</style>
