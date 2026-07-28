<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { PersonSummary, PhotoSummary } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'

const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()

const person = ref<PersonSummary | null>(null)
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
  person.value = null
  photos.value = []
  try {
    const [personResult, photosResult] = await Promise.all([
      api.getPerson(props.id),
      api.getPersonPhotos(props.id, { page: page.value, perPage }),
    ])
    person.value = personResult
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
    name: 'person',
    params: { id: props.id },
    query: next > 1 ? { page: String(next) } : {},
  })
}
</script>

<template>
  <section>
    <p v-if="loading">Carregando…</p>
    <p v-else-if="notFound">Pessoa não encontrada.</p>
    <template v-else-if="person">
      <h1>{{ person.name ?? 'Pessoa sem nome' }}</h1>
      <PhotoGrid :photos="photos" />
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
    </template>
  </section>
</template>

<style scoped>
.pager {
  margin-top: 1.5rem;
}
</style>
