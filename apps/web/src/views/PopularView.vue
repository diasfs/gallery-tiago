<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumSummary, PhotoSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PhotoGrid from '../components/PhotoGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { usePageMeta } from '../composables/usePageMeta'

const route = useRoute()
const router = useRouter()

const photos = ref<PhotoSummary[]>([])
const albums = ref<AlbumSummary[]>([])
const total = ref(0)
const perPage = 48
const loading = ref(true)
const error = ref<string | null>(null)

const kind = computed<'photos' | 'albums'>(() =>
  route.query.kind === 'albums' ? 'albums' : 'photos',
)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

usePageMeta(
  computed(() => ({
    title: 'Mais vistos · Gallery',
    description: 'Fotos e álbuns mais populares da galeria',
  })),
)

async function load() {
  loading.value = true
  error.value = null
  photos.value = []
  albums.value = []
  try {
    if (kind.value === 'albums') {
      const result = await api.listMostViewedAlbums({ page: page.value, perPage })
      albums.value = result.data
      total.value = result.meta.total
    } else {
      const result = await api.listMostViewedPhotos({ page: page.value, perPage })
      photos.value = result.data
      total.value = result.meta.total
    }
  } catch {
    error.value = 'Não foi possível carregar o ranking.'
    total.value = 0
  } finally {
    loading.value = false
  }
}

watch(
  () => [kind.value, page.value] as const,
  () => {
    void load()
  },
  { immediate: true },
)

function setKind(next: 'photos' | 'albums') {
  router.push({
    name: 'popular',
    query: next === 'albums' ? { kind: 'albums' } : {},
  })
}

function setPage(next: number) {
  const query: Record<string, string> = {}
  if (kind.value === 'albums') {
    query.kind = 'albums'
  }
  if (next > 1) {
    query.page = String(next)
  }
  router.push({ name: 'popular', query })
}
</script>

<template>
  <section>
    <h1>Mais vistos</h1>
    <div class="tabs">
      <button
        type="button"
        class="tab"
        :class="{ 'tab--active': kind === 'photos' }"
        data-testid="popular-tab-photos"
        @click="setKind('photos')"
      >
        Fotos
      </button>
      <button
        type="button"
        class="tab"
        :class="{ 'tab--active': kind === 'albums' }"
        data-testid="popular-tab-albums"
        @click="setKind('albums')"
      >
        Álbuns
      </button>
    </div>

    <p v-if="loading">Carregando ranking…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <template v-else>
      <p v-if="kind === 'photos' && photos.length === 0">Nenhuma foto com visualizações ainda.</p>
      <PhotoGrid v-else-if="kind === 'photos'" :photos="photos" />
      <p v-else-if="albums.length === 0">Nenhum álbum com visualizações ainda.</p>
      <AlbumGrid v-else :albums="albums" />
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
      <p class="note">Ranking por visualizações totais.</p>
    </template>
  </section>
</template>

<style scoped>
.tabs {
  display: flex;
  gap: 0.5rem;
  margin: 0 0 1.5rem;
}

.tab {
  border: 1px solid var(--border, #333);
  background: transparent;
  color: inherit;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  cursor: pointer;
}

.tab--active {
  background: var(--foreground, #fff);
  color: var(--background, #111);
}

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
