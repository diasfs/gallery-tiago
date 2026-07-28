<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, photoDisplayUrl } from '../api/client'
import type { AlbumSummary } from '../api/types'
import { formatAlbumDateRangeLabel } from '../lib/utils'

const props = defineProps<{
  albums: AlbumSummary[]
}>()

const coverThumbs = ref<Record<string, string | null>>({})

function coverThumb(album: AlbumSummary): string | null {
  return album.coverPhotoId ? (coverThumbs.value[album.coverPhotoId] ?? null) : null
}

function subtitle(album: AlbumSummary): string | null {
  const description = album.description?.trim()
  if (description) {
    return description
  }
  return formatAlbumDateRangeLabel(album.takenAt, album.takenAtEnd)
}

async function loadCovers(albums: AlbumSummary[]) {
  const coverIds = [
    ...new Set(albums.map((album) => album.coverPhotoId).filter((id): id is string => !!id)),
  ]
  const missing = coverIds.filter((id) => !(id in coverThumbs.value))
  if (missing.length === 0) {
    return
  }
  const covers = await Promise.allSettled(missing.map((id) => api.getPhoto(id)))
  covers.forEach((result, index) => {
    if (result.status === 'fulfilled') {
      coverThumbs.value[missing[index]] = photoDisplayUrl(result.value)
    }
  })
}

watch(
  () => props.albums,
  (albums) => {
    void loadCovers(albums)
  },
  { immediate: true },
)
</script>

<template>
  <div class="album-grid" data-testid="album-grid">
    <RouterLink
      v-for="album in albums"
      :key="album.id"
      :to="{ name: 'album', params: { slug: album.slug } }"
      class="album-card"
      data-testid="album-card"
    >
      <img
        v-if="coverThumb(album)"
        :src="coverThumb(album)!"
        :alt="album.title"
        loading="lazy"
      />
      <div v-else class="album-card__placeholder">{{ album.title.charAt(0) }}</div>
      <div class="album-card__body">
        <h2>{{ album.title }}</h2>
        <p v-if="subtitle(album)">{{ subtitle(album) }}</p>
      </div>
    </RouterLink>
  </div>
</template>

<style scoped>
.album-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1.25rem;
}

.album-card {
  display: block;
  border-radius: 10px;
  overflow: hidden;
  background: #1a1a1a;
  text-decoration: none;
  color: inherit;
  transition: transform 0.15s ease;
}

.album-card:hover {
  transform: translateY(-2px);
}

.album-card img,
.album-card__placeholder {
  aspect-ratio: 4 / 3;
  width: 100%;
  object-fit: cover;
  display: block;
}

.album-card__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #2a2a2a;
  font-size: 2rem;
  color: #888;
}

.album-card__body {
  padding: 0.85rem 1rem 1rem;
}

.album-card__body h2 {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
}

.album-card__body p {
  margin: 0;
  font-size: 0.9rem;
  color: #aaa;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
