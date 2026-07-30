<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { PhotoSummary } from '../api/types'
import { photoDisplayUrl } from '../api/client'
import ViewCount from './ViewCount.vue'

defineProps<{
  photos: PhotoSummary[]
}>()

function thumbSrc(photo: PhotoSummary): string | null {
  return photoDisplayUrl(photo)
}
</script>

<template>
  <div class="photo-grid">
    <p v-if="photos.length === 0" class="photo-grid__empty">Nenhuma foto ainda.</p>
    <RouterLink
      v-for="photo in photos"
      :key="photo.id"
      :to="{ name: 'photo', params: { id: photo.id } }"
      class="photo-grid__item"
    >
      <img
        v-if="thumbSrc(photo)"
        :src="thumbSrc(photo)!"
        :alt="photo.title ?? 'Foto sem título'"
        loading="lazy"
      />
      <div v-else class="photo-grid__placeholder">Sem pré-visualização</div>
      <span class="photo-grid__views">
        <ViewCount :count="photo.viewCount" />
      </span>
    </RouterLink>
  </div>
</template>

<style scoped>
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1rem;
}

.photo-grid__empty {
  color: var(--muted, #888);
  grid-column: 1 / -1;
}

.photo-grid__item {
  position: relative;
  display: block;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border-radius: 8px;
  background: #1a1a1a;
  text-decoration: none;
  color: inherit;
}

.photo-grid__item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.2s ease;
}

.photo-grid__item:hover img {
  transform: scale(1.04);
}

.photo-grid__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted, #888);
  font-size: 0.85rem;
}

.photo-grid__views {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 0.4rem 0.6rem;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.7), transparent);
  color: #fff;
}
</style>
