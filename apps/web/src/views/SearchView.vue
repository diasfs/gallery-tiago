<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { AlbumSummary, PhotoSummary } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import PhotoGrid from '../components/PhotoGrid.vue'
import PublicSearchBar, { type PublicSearchBarState } from '../components/PublicSearchBar.vue'
import {
  hasSearchCriteria,
  resolveSearchPillLabels,
  searchParamsFromState,
  searchRouteQuery,
  searchStateFromQuery,
} from '../lib/publicSearch'

const route = useRoute()
const router = useRouter()

const bar = ref<PublicSearchBarState>(searchStateFromQuery(route.query))
const albums = ref<AlbumSummary[]>([])
const photos = ref<PhotoSummary[]>([])
const albumTotal = ref(0)
const photoTotal = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)
const idle = ref(true)

const albumPage = computed(() => {
  const raw = Number(route.query.albumPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const photoPage = computed(() => {
  const raw = Number(route.query.photoPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

async function loadFromRoute() {
  const next = searchStateFromQuery(route.query)
  bar.value = await resolveSearchPillLabels(next)

  if (!hasSearchCriteria(bar.value)) {
    idle.value = true
    loading.value = false
    error.value = null
    albums.value = []
    photos.value = []
    albumTotal.value = 0
    photoTotal.value = 0
    return
  }

  idle.value = false
  loading.value = true
  error.value = null
  try {
    const result = await api.search(
      searchParamsFromState(bar.value, {
        albumPage: albumPage.value,
        photoPage: photoPage.value,
      }),
    )
    albums.value = result.data.albums
    photos.value = result.data.photos
    albumTotal.value = result.meta.albums.total
    photoTotal.value = result.meta.photos.total
  } catch {
    error.value = 'Não foi possível realizar a busca. Tente novamente.'
    albums.value = []
    photos.value = []
    albumTotal.value = 0
    photoTotal.value = 0
  } finally {
    loading.value = false
  }
}

function onSubmit(state: PublicSearchBarState) {
  bar.value = state
  void router.push({ name: 'search', query: searchRouteQuery(state) })
}

function setAlbumPage(page: number) {
  void router.push({
    name: 'search',
    query: searchRouteQuery(bar.value, { albumPage: page, photoPage: photoPage.value }),
  })
}

function setPhotoPage(page: number) {
  void router.push({
    name: 'search',
    query: searchRouteQuery(bar.value, { albumPage: albumPage.value, photoPage: page }),
  })
}

watch(
  () => route.query,
  () => {
    void loadFromRoute()
  },
  { immediate: true },
)
</script>

<template>
  <section class="search-page">
    <h1>Busca</h1>
    <PublicSearchBar v-model="bar" data-testid="search-page-bar" @submit="onSubmit" />

    <p v-if="idle" class="search-page__hint" data-testid="search-idle">
      Digite uma consulta, pessoas, tags ou uma data para encontrar álbuns e fotos públicos.
    </p>
    <p v-else-if="loading" data-testid="search-loading">Buscando…</p>
    <p v-else-if="error" class="error" data-testid="search-error">{{ error }}</p>
    <template v-else>
      <section class="search-page__section" data-testid="search-albums">
        <h2>Álbuns <span class="search-page__count">({{ albumTotal }})</span></h2>
        <p v-if="albums.length === 0" class="search-page__empty">Nenhum álbum encontrado.</p>
        <template v-else>
          <AlbumGrid :albums="albums" />
          <PaginationBar
            class="pager"
            :page="albumPage"
            :total="albumTotal"
            :per-page="24"
            @update:page="setAlbumPage"
          />
        </template>
      </section>

      <section class="search-page__section" data-testid="search-photos">
        <h2>Fotos <span class="search-page__count">({{ photoTotal }})</span></h2>
        <p v-if="photos.length === 0" class="search-page__empty">Nenhuma foto encontrada.</p>
        <template v-else>
          <PhotoGrid :photos="photos" />
          <PaginationBar
            class="pager"
            :page="photoPage"
            :total="photoTotal"
            :per-page="48"
            @update:page="setPhotoPage"
          />
        </template>
      </section>
    </template>
  </section>
</template>

<style scoped>
.search-page {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.search-page__hint,
.search-page__empty,
.search-page__count {
  color: var(--muted, #888);
}

.search-page__section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

.search-page__section h2 {
  margin: 0;
  font-size: 1.15rem;
}

.pager {
  margin-top: 0.5rem;
}

.error {
  color: #f87171;
}
</style>
