<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useResizeObserver } from '@vueuse/core'
import { RouterLink } from 'vue-router'
import type { AlbumPhotoLayout, PhotoSummary } from '../api/types'
import { photoDisplayUrl } from '../api/client'
import { photoPath } from '../lib/publicPaths'
import { computeJustifiedPhotoLayout } from '../lib/justifiedPhotoLayout'
import ViewCount from './ViewCount.vue'

const props = withDefaults(
  defineProps<{
    photos: PhotoSummary[]
    lightbox?: boolean
    layout?: AlbumPhotoLayout
  }>(),
  {
    layout: 'grid',
  },
)

const emit = defineEmits<{
  select: [id: string]
}>()

const containerRef = ref<HTMLElement | null>(null)
const containerWidth = ref(0)
const TARGET_ROW_HEIGHT = 200
const GAP_PX = 16

useResizeObserver(containerRef, (entries) => {
  const entry = entries[0]
  containerWidth.value = entry?.contentRect.width ?? 0
})

const justifiedTiles = computed(() => {
  if (props.layout !== 'masonry_horizontal' || containerWidth.value <= 0) {
    return new Map<string, { width: number; height: number }>()
  }

  return new Map(
    computeJustifiedPhotoLayout(props.photos, containerWidth.value, TARGET_ROW_HEIGHT, GAP_PX).map(
      (tile) => [tile.photoId, { width: tile.width, height: tile.height }],
    ),
  )
})

watch(
  () => props.photos,
  () => {
    if (containerRef.value) {
      containerWidth.value = containerRef.value.clientWidth
    }
  },
  { deep: true },
)

const gridClass = computed(() => {
  switch (props.layout) {
    case 'masonry_vertical':
      return 'photo-grid photo-grid--masonry-vertical'
    case 'masonry_horizontal':
      return 'photo-grid photo-grid--masonry-horizontal'
    default:
      return 'photo-grid'
  }
})

function photoLink(photo: PhotoSummary) {
  if (photo.albumSlug && photo.filename) {
    return photoPath({ albumSlug: photo.albumSlug, filename: photo.filename })
  }

  return { name: 'photo-legacy', params: { id: photo.id } }
}

function onPhotoClick(photo: PhotoSummary, event: MouseEvent) {
  if (!props.lightbox) return
  event.preventDefault()
  emit('select', photo.id)
}

function thumbSrc(photo: PhotoSummary): string | null {
  return photoDisplayUrl(photo)
}

function itemStyle(photo: PhotoSummary): Record<string, string> | undefined {
  if (props.layout !== 'masonry_horizontal') {
    return undefined
  }

  const tile = justifiedTiles.value.get(photo.id)
  if (!tile) {
    return undefined
  }

  return {
    width: `${tile.width}px`,
    height: `${tile.height}px`,
  }
}
</script>

<template>
  <div ref="containerRef" :class="gridClass">
    <p v-if="photos.length === 0" class="photo-grid__empty">Nenhuma foto ainda.</p>
    <component
      :is="lightbox ? 'button' : RouterLink"
      v-for="photo in photos"
      :key="photo.id"
      :to="lightbox ? undefined : photoLink(photo)"
      :type="lightbox ? 'button' : undefined"
      class="photo-grid__item"
      :style="itemStyle(photo)"
      :data-testid="lightbox ? 'photo-grid-lightbox-trigger' : undefined"
      @click="onPhotoClick(photo, $event)"
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
    </component>
  </div>
</template>

<style scoped>
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1rem;
}

.photo-grid--masonry-vertical {
  display: block;
  column-gap: 1rem;
  column-count: 2;
}

.photo-grid--masonry-horizontal {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.photo-grid__empty {
  color: var(--muted, #888);
  grid-column: 1 / -1;
}

.photo-grid__item {
  position: relative;
  display: block;
  width: 100%;
  border: 0;
  padding: 0;
  cursor: pointer;
  font: inherit;
  text-align: inherit;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border-radius: 8px;
  background: #1a1a1a;
  text-decoration: none;
  color: inherit;
}

.photo-grid--masonry-vertical .photo-grid__item {
  aspect-ratio: auto;
  width: 100%;
  margin-bottom: 1rem;
  break-inside: avoid;
}

.photo-grid--masonry-horizontal .photo-grid__item {
  aspect-ratio: auto;
  width: auto;
  flex: 0 0 auto;
}

.photo-grid__item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.2s ease;
}

.photo-grid--masonry-vertical .photo-grid__item img {
  height: auto;
  object-fit: contain;
}

.photo-grid--masonry-horizontal .photo-grid__item img {
  object-fit: cover;
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

@media (min-width: 640px) {
  .photo-grid--masonry-vertical {
    column-count: 3;
  }
}

@media (min-width: 1024px) {
  .photo-grid--masonry-vertical {
    column-count: 4;
  }
}
</style>
