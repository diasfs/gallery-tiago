<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { OnThisDayAlbum } from '../api/types'
import AlbumGrid from '../components/AlbumGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { usePageMeta } from '../composables/usePageMeta'
import { formatOnThisDayHeading, formatYearsAgo } from '../lib/utils'

const route = useRoute()
const router = useRouter()

const albums = ref<OnThisDayAlbum[]>([])
const headingMonth = ref<number | null>(null)
const headingDay = ref<number | null>(null)
const total = ref(0)
const perPage = 48
const loading = ref(true)
const error = ref<string | null>(null)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const heading = computed(() => {
  if (headingMonth.value == null || headingDay.value == null) {
    return 'Neste dia'
  }
  return `Neste dia · ${formatOnThisDayHeading(headingMonth.value, headingDay.value)}`
})

usePageMeta(
  computed(() => ({
    title: `${heading.value} · Gallery`,
    description: 'Álbuns de anos anteriores com data neste dia',
  })),
)

const groupedAlbums = computed(() => {
  const groups = new Map<number, OnThisDayAlbum[]>()
  for (const album of albums.value) {
    const bucket = groups.get(album.yearsAgo) ?? []
    bucket.push(album)
    groups.set(album.yearsAgo, bucket)
  }

  return [...groups.entries()]
    .sort(([left], [right]) => right - left)
    .map(([yearsAgo, items]) => ({ yearsAgo, items }))
})

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await api.listOnThisDayAlbums({ page: page.value, perPage })
    albums.value = result.data
    total.value = result.meta.total
    headingMonth.value = result.meta.month
    headingDay.value = result.meta.day
  } catch {
    error.value = 'Não foi possível carregar as memórias.'
    albums.value = []
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
    name: 'memories',
    query: next > 1 ? { page: String(next) } : {},
  })
}
</script>

<template>
  <section>
    <h1>{{ heading }}</h1>
    <p class="intro">Álbuns de anos anteriores com data neste dia.</p>
    <p v-if="loading">Carregando memórias…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="albums.length === 0">Nenhuma memória para este dia ainda.</p>
    <template v-else>
      <section v-for="group in groupedAlbums" :key="group.yearsAgo" class="group">
        <h2>{{ formatYearsAgo(group.yearsAgo) }}</h2>
        <AlbumGrid :albums="group.items" />
      </section>
      <PaginationBar class="pager" :page="page" :total="total" :per-page="perPage" @update:page="setPage" />
    </template>
  </section>
</template>

<style scoped>
.intro {
  margin: 0 0 1.5rem;
  color: var(--muted, #888);
}

.group + .group {
  margin-top: 2rem;
}

.group h2 {
  margin: 0 0 1rem;
  font-size: 1.1rem;
}

.pager {
  margin-top: 1.5rem;
}

.error {
  color: #f87171;
}
</style>
