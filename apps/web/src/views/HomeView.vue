<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'

const route = useRoute()
const router = useRouter()

const albums = ref<AlbumSummary[]>([])
const total = ref(0)
const perPage = 24
const loading = ref(true)
const error = ref<string | null>(null)

const recentAlbums = ref<AlbumSummary[]>([])
const recentLoading = ref(true)
const recentError = ref<string | null>(null)

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

.error {
  color: #f87171;
}
</style>
