<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, mediaUrl } from '../api/client'
import type { AlbumSummary } from '../api/types'

const albums = ref<AlbumSummary[]>([])
const coverThumbs = ref<Record<string, string | null>>({})
const loading = ref(true)
const error = ref<string | null>(null)

function coverThumb(album: AlbumSummary): string | null {
  return album.coverPhotoId ? (coverThumbs.value[album.coverPhotoId] ?? null) : null
}

onMounted(async () => {
  try {
    albums.value = await api.listAlbums()
    const coverIds = [...new Set(albums.value.map((a) => a.coverPhotoId).filter((id): id is string => !!id))]
    const covers = await Promise.allSettled(coverIds.map((id) => api.getPhoto(id)))
    covers.forEach((result, index) => {
      if (result.status === 'fulfilled') {
        const photo = result.value
        const thumb = Object.values(photo.thumbPaths ?? {})[0] ?? photo.avifPath
        coverThumbs.value[coverIds[index]] = mediaUrl(thumb)
      }
    })
  } catch {
    error.value = 'Could not load albums. Please try again later.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <h1>Albums</h1>
    <p v-if="loading">Loading albums…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="albums.length === 0">No public albums yet.</p>
    <div v-else class="album-grid">
      <RouterLink
        v-for="album in albums"
        :key="album.id"
        :to="{ name: 'album', params: { slug: album.slug } }"
        class="album-card"
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
          <p v-if="album.description">{{ album.description }}</p>
        </div>
      </RouterLink>
    </div>
  </section>
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

.album-card img {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  display: block;
}

.album-card__placeholder {
  width: 100%;
  aspect-ratio: 4 / 3;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  color: var(--muted, #888);
  background: #222;
}

.album-card__body {
  padding: 0.75rem 1rem;
}

.album-card__body h2 {
  font-size: 1.05rem;
  margin: 0 0 0.25rem;
}

.album-card__body p {
  font-size: 0.85rem;
  color: var(--muted, #888);
  margin: 0;
}

.error {
  color: #e5484d;
}
</style>
