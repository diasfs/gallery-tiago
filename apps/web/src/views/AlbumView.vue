<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumDetail, AlbumSummary, PhotoSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PhotoGrid from '../components/PhotoGrid.vue'
import Breadcrumb from '../components/Breadcrumb.vue'
import LocationMap from '../components/LocationMap.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { formatAlbumDateRangeLabel } from '../lib/utils'

const props = defineProps<{ slug: string }>()
const route = useRoute()
const router = useRouter()

const album = ref<AlbumDetail | null>(null)
const children = ref<AlbumSummary[]>([])
const photos = ref<PhotoSummary[]>([])
const childrenTotal = ref(0)
const photosTotal = ref(0)
const childrenPerPage = 24
const photosPerPage = 48
const loading = ref(true)
const notFound = ref(false)

const childrenPage = computed(() => {
  const raw = Number(route.query.childrenPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const photosPage = computed(() => {
  const raw = Number(route.query.photosPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

async function load() {
  loading.value = true
  notFound.value = false
  album.value = null
  try {
    const [detail, childrenResult, photosResult] = await Promise.all([
      api.getAlbum(props.slug),
      api.listAlbumChildren(props.slug, { page: childrenPage.value, perPage: childrenPerPage }),
      api.listAlbumPhotos(props.slug, { page: photosPage.value, perPage: photosPerPage }),
    ])
    album.value = detail
    children.value = childrenResult.data
    childrenTotal.value = childrenResult.meta.total
    photos.value = photosResult.data
    photosTotal.value = photosResult.meta.total
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.slug, childrenPage.value, photosPage.value] as const,
  () => {
    void load()
  },
  { immediate: true },
)

function setChildrenPage(next: number) {
  router.push({
    name: 'album',
    params: { slug: props.slug },
    query: {
      ...route.query,
      ...(next > 1 ? { childrenPage: String(next) } : { childrenPage: undefined }),
    },
  })
}

function setPhotosPage(next: number) {
  router.push({
    name: 'album',
    params: { slug: props.slug },
    query: {
      ...route.query,
      ...(next > 1 ? { photosPage: String(next) } : { photosPage: undefined }),
    },
  })
}

const takenAtLabel = computed(() =>
  formatAlbumDateRangeLabel(album.value?.takenAt, album.value?.takenAtEnd),
)

const hasCoordinates = computed(
  () => album.value?.location?.latitude != null && album.value?.location?.longitude != null,
)
</script>

<template>
  <section>
    <p v-if="loading">Carregando álbum…</p>
    <p v-else-if="notFound">Álbum não encontrado.</p>
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

      <div v-if="childrenTotal > 0" class="sub-albums">
        <AlbumGrid :albums="children" />
        <PaginationBar
          class="sub-albums__pager"
          :page="childrenPage"
          :total="childrenTotal"
          :per-page="childrenPerPage"
          @update:page="setChildrenPage"
        />
      </div>

      <PhotoGrid :photos="photos" />
      <PaginationBar
        class="photos-pager"
        :page="photosPage"
        :total="photosTotal"
        :per-page="photosPerPage"
        @update:page="setPhotosPage"
      />
    </template>
  </section>
</template>

<style scoped>
.album-description {
  max-width: 40rem;
  color: #bbb;
}

.album-date {
  color: #999;
  font-size: 0.9rem;
}

.album-location {
  margin: 1rem 0 1.5rem;
}

.sub-albums {
  margin-bottom: 1.5rem;
}

.sub-albums__pager,
.photos-pager {
  width: 100%;
  margin-top: 0.75rem;
}
</style>
