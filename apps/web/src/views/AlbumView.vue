<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api/client'
import type { AlbumDetail } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import Breadcrumb from '../components/Breadcrumb.vue'
import LocationMap from '../components/LocationMap.vue'
import { formatDateLabel } from '../lib/utils'

const props = defineProps<{ slug: string }>()

const album = ref<AlbumDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load(slug: string) {
  loading.value = true
  notFound.value = false
  album.value = null
  try {
    album.value = await api.getAlbum(slug)
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => load(props.slug))
watch(() => props.slug, load)

const takenAtLabel = computed(() => {
  if (!album.value?.takenAt) {
    return null
  }
  return formatDateLabel(album.value.takenAt)
})

const hasCoordinates = computed(
  () => album.value?.location?.latitude != null && album.value?.location?.longitude != null,
)
</script>

<template>
  <section>
    <p v-if="loading">Loading album…</p>
    <p v-else-if="notFound">Album not found.</p>
    <template v-else-if="album">
      <Breadcrumb :ancestors="album.ancestors" :current="album.title" />
      <h1>{{ album.title }}</h1>
      <p v-if="album.description" class="album-description">{{ album.description }}</p>
      <p v-if="takenAtLabel" class="album-date">{{ takenAtLabel }}</p>

      <div v-if="album.location" class="album-location">
        <p>
          📍 {{ [album.location.name, album.location.city, album.location.country].filter(Boolean).join(', ') }}
        </p>
        <LocationMap
          v-if="hasCoordinates"
          :latitude="album.location.latitude!"
          :longitude="album.location.longitude!"
          :label="album.location.name"
        />
      </div>

      <div v-if="album.children.length > 0" class="sub-albums">
        <RouterLink
          v-for="child in album.children"
          :key="child.id"
          :to="{ name: 'album', params: { slug: child.slug } }"
          class="sub-albums__item"
        >
          {{ child.title }}
        </RouterLink>
      </div>

      <PhotoGrid :photos="album.photos" />
    </template>
  </section>
</template>

<style scoped>
.album-description {
  color: var(--muted, #888);
  margin-bottom: 0.75rem;
}

.album-date {
  color: var(--muted, #888);
  margin: 0 0 1.25rem;
}

.album-location {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.album-location p {
  margin: 0;
  color: var(--muted, #888);
}

.sub-albums {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-bottom: 1.5rem;
}

.sub-albums__item {
  padding: 0.4rem 0.9rem;
  border-radius: 999px;
  background: #1a1a1a;
  color: inherit;
  text-decoration: none;
  font-size: 0.9rem;
}

.sub-albums__item:hover {
  background: #262626;
}
</style>
