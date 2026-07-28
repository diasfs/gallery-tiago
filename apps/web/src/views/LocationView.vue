<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { LocationDetail, PhotoSummary } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import LocationMap from '../components/LocationMap.vue'
import PaginationBar from '../components/PaginationBar.vue'

const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()

const detail = ref<LocationDetail | null>(null)
const photos = ref<PhotoSummary[]>([])
const total = ref(0)
const perPage = 48
const loading = ref(true)
const notFound = ref(false)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

async function load() {
  loading.value = true
  notFound.value = false
  detail.value = null
  photos.value = []
  try {
    const [locationResult, photosResult] = await Promise.all([
      api.getLocation(props.id),
      api.listLocationPhotos(props.id, { page: page.value, perPage }),
    ])
    detail.value = locationResult
    photos.value = photosResult.data
    total.value = photosResult.meta.total
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.id, page.value] as const,
  () => {
    void load()
  },
  { immediate: true },
)

function setPage(next: number) {
  router.push({
    name: 'location',
    params: { id: props.id },
    query: next > 1 ? { page: String(next) } : {},
  })
}

const hasCoordinates = computed(
  () => detail.value?.location.latitude != null && detail.value?.location.longitude != null,
)
</script>

<template>
  <section>
    <p v-if="loading">Carregando…</p>
    <p v-else-if="notFound">Local não encontrado.</p>
    <template v-else-if="detail">
      <h1>{{ detail.location.name }}</h1>
      <p v-if="detail.location.city || detail.location.country" class="location-subtitle">
        {{ [detail.location.city, detail.location.country].filter(Boolean).join(', ') }}
      </p>
      <LocationMap
        v-if="hasCoordinates"
        :latitude="detail.location.latitude!"
        :longitude="detail.location.longitude!"
        :label="detail.location.name"
      />
      <PhotoGrid :photos="photos" />
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
    </template>
  </section>
</template>

<style scoped>
.location-subtitle {
  color: var(--muted, #888);
  margin-top: -0.5rem;
}

.pager {
  margin-top: 1.5rem;
}
</style>
