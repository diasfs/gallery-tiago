<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { useSiteConfig } from '../composables/useSiteConfig'

const route = useRoute()
const router = useRouter()
const { mostViewedHomeEnabled, loading: siteConfigLoading } = useSiteConfig()

const albums = ref<AlbumSummary[]>([])
const total = ref(0)
const perPage = 24
const loading = ref(true)
const error = ref<string | null>(null)

const recentAlbums = ref<AlbumSummary[]>([])
const recentLoading = ref(true)
const recentError = ref<string | null>(null)

const memoryAlbums = ref<AlbumSummary[]>([])
const memoryLoading = ref(true)
const popularAlbums = ref<AlbumSummary[]>([])
const popularLoading = ref(true)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

function setPage(next: number) {
  router.push({
    name: 'home',
    query: next > 1 ? { page: String(next) } : {},
  })
}

async function loadRoots() {
  loading.value = true
  error.value = null
  try {
    const result = await api.listAlbums({ page: page.value, perPage })
    albums.value = result.data
    total.value = result.meta.total
  } catch {
    error.value = 'Não foi possível carregar os álbuns. Tente novamente mais tarde.'
  } finally {
    loading.value = false
  }
}

async function loadRecent() {
  recentLoading.value = true
  recentError.value = null
  try {
    recentAlbums.value = await api.listRecentAlbums({ limit: 12 })
  } catch {
    recentAlbums.value = []
    recentError.value = 'Não foi possível carregar os álbuns adicionados recentemente.'
  } finally {
    recentLoading.value = false
  }
}

watch(
  () => route.query.page,
  () => {
    void loadRoots()
  },
  { immediate: true },
)

async function loadDiscoverTeasers() {
  memoryLoading.value = true
  popularLoading.value = mostViewedHomeEnabled.value
  try {
    const memoriesPromise = api.listOnThisDayAlbums({ perPage: 6 })
    const popularPromise = mostViewedHomeEnabled.value
      ? api.listMostViewedAlbums({ perPage: 6 })
      : Promise.resolve({ data: [] as AlbumSummary[] })
    const [memories, popular] = await Promise.all([memoriesPromise, popularPromise])
    memoryAlbums.value = memories.data
    popularAlbums.value = popular.data
  } catch {
    memoryAlbums.value = []
    popularAlbums.value = []
  } finally {
    memoryLoading.value = false
    popularLoading.value = false
  }
}

watch(
  siteConfigLoading,
  (loadingConfig) => {
    if (!loadingConfig) {
      void loadDiscoverTeasers()
    }
  },
  { immediate: true },
)

onMounted(() => {
  void loadRecent()
})
</script>

<template>
  <section>
    <h1>Álbuns</h1>
    <p v-if="loading">Carregando álbuns…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="albums.length === 0">Nenhum álbum público ainda.</p>
    <template v-else>
      <AlbumGrid :albums="albums" />
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
    </template>
  </section>

  <section
    v-if="recentLoading || recentError || recentAlbums.length > 0"
    class="recent"
    data-testid="recent-albums"
  >
    <h2>Adicionados recentemente</h2>
    <p v-if="recentLoading">Carregando álbuns adicionados recentemente…</p>
    <p v-else-if="recentError" class="error">{{ recentError }}</p>
    <AlbumGrid v-else :albums="recentAlbums" />
  </section>

  <section
    v-if="memoryLoading || memoryAlbums.length > 0"
    class="discover"
    data-testid="memory-teaser"
  >
    <div class="discover__header">
      <h2>Neste dia</h2>
      <RouterLink to="/memories">Ver tudo</RouterLink>
    </div>
    <p v-if="memoryLoading">Carregando memórias…</p>
    <AlbumGrid v-else :albums="memoryAlbums" />
  </section>

  <section
    v-if="mostViewedHomeEnabled && (popularLoading || popularAlbums.length > 0)"
    class="discover"
    data-testid="popular-teaser"
  >
    <div class="discover__header">
      <h2>Mais vistos</h2>
      <RouterLink to="/popular">Ver tudo</RouterLink>
    </div>
    <p v-if="popularLoading">Carregando ranking…</p>
    <AlbumGrid v-else :albums="popularAlbums" />
  </section>
</template>

<style scoped>
.pager {
  margin-top: 1.5rem;
}

.recent {
  margin-top: 2.5rem;
}

.recent h2 {
  margin: 0 0 1rem;
  font-size: 1.25rem;
}

.discover {
  margin-top: 2.5rem;
}

.discover__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.discover__header h2 {
  margin: 0;
  font-size: 1.25rem;
}

.discover__header a {
  color: var(--muted, #888);
  font-size: 0.9rem;
}

.error {
  color: #f87171;
}
</style>
