<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { usePageMeta } from '../composables/usePageMeta'

const route = useRoute()
const router = useRouter()

const albums = ref<AlbumSummary[]>([])
const total = ref(0)
const perPage = 48
const loading = ref(true)
const error = ref<string | null>(null)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

usePageMeta(
  computed(() => ({
    title: 'Mais vistos · Gallery',
    description: 'Álbuns mais populares da galeria',
  })),
)

async function load() {
  loading.value = true
  error.value = null
  albums.value = []
  try {
    const result = await api.listMostViewedAlbums({ page: page.value, perPage })
    albums.value = result.data
    total.value = result.meta.total
  } catch {
    error.value = 'Não foi possível carregar o ranking.'
    total.value = 0
  } finally {
    loading.value = false
  }
}

watch(
  () => page.value,
  () => {
    void load()
  },
  { immediate: true },
)

function setPage(next: number) {
  router.push({
    name: 'popular',
    query: next > 1 ? { page: String(next) } : {},
  })
}
</script>

<template>
  <section>
    <h1>Mais vistos</h1>

    <p v-if="loading">Carregando ranking…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <template v-else>
      <p v-if="albums.length === 0">Nenhum álbum com visualizações ainda.</p>
      <AlbumGrid v-else :albums="albums" />
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
      <p class="note">Ranking por visualizações totais.</p>
    </template>
  </section>
</template>

<style scoped>
.pager {
  margin-top: 1.5rem;
}

.note {
  margin-top: 1rem;
  color: var(--muted, #888);
  font-size: 0.85rem;
}

.error {
  color: #f87171;
}
</style>
