<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import type { PhotoSummary, TimelineMonth } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import { usePageMeta } from '../composables/usePageMeta'
import { formatTimelineMonthLabel } from '../lib/utils'

const props = defineProps<{ year?: string; month?: string }>()
const route = useRoute()
const router = useRouter()

const months = ref<TimelineMonth[]>([])
const photos = ref<PhotoSummary[]>([])
const photoTotal = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const perPage = 48

const selectedYear = computed(() => {
  const raw = Number(props.year)
  return Number.isFinite(raw) ? raw : null
})

const selectedMonth = computed(() => {
  const raw = Number(props.month)
  return Number.isFinite(raw) ? raw : null
})

const isMonthView = computed(() => selectedYear.value != null && selectedMonth.value != null)

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const monthLabel = computed(() => {
  if (!isMonthView.value || selectedYear.value == null || selectedMonth.value == null) {
    return null
  }
  return formatTimelineMonthLabel(selectedYear.value, selectedMonth.value)
})

usePageMeta(
  computed(() => {
    if (isMonthView.value && monthLabel.value) {
      return {
        title: `${monthLabel.value} · Timeline · Gallery`,
        description: `Fotos de ${monthLabel.value}`,
      }
    }
    return {
      title: 'Timeline · Gallery',
      description: 'Fotos agrupadas por mês',
    }
  }),
)

async function loadMonths() {
  loading.value = true
  error.value = null
  try {
    months.value = await api.listTimelineMonths()
  } catch {
    error.value = 'Não foi possível carregar a timeline.'
    months.value = []
  } finally {
    loading.value = false
  }
}

async function loadPhotos() {
  if (!isMonthView.value || selectedYear.value == null || selectedMonth.value == null) {
    return
  }
  loading.value = true
  error.value = null
  try {
    const result = await api.listTimelinePhotos({
      year: selectedYear.value,
      month: selectedMonth.value,
      page: page.value,
      perPage,
    })
    photos.value = result.data
    photoTotal.value = result.meta.total
  } catch {
    error.value = 'Não foi possível carregar as fotos deste mês.'
    photos.value = []
    photoTotal.value = 0
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.year, props.month] as const,
  () => {
    if (isMonthView.value) {
      void loadPhotos()
    } else {
      void loadMonths()
    }
  },
  { immediate: true },
)

watch(page, () => {
  if (isMonthView.value) {
    void loadPhotos()
  }
})

function monthLink(year: number, month: number) {
  return { name: 'timeline-month' as const, params: { year: String(year), month: String(month) } }
}

function setPage(next: number) {
  router.push({
    name: 'timeline-month',
    params: { year: props.year!, month: props.month! },
    query: next > 1 ? { page: String(next) } : {},
  })
}

function monthHeading(entry: TimelineMonth): string {
  return formatTimelineMonthLabel(entry.year, entry.month)
}
</script>

<template>
  <section>
    <template v-if="isMonthView">
      <p class="timeline__back">
        <RouterLink :to="{ name: 'timeline' }">← Timeline</RouterLink>
      </p>
      <h1>{{ monthLabel }}</h1>
    </template>
    <template v-else>
      <h1>Timeline</h1>
      <p class="timeline__intro">Fotos agrupadas por mês (data do álbum ou envio).</p>
    </template>

    <p v-if="loading">Carregando…</p>
    <p v-else-if="error">{{ error }}</p>

    <template v-else-if="isMonthView">
      <PhotoGrid v-if="photos.length > 0" :photos="photos" />
      <p v-else class="timeline__empty">Nenhuma foto neste mês.</p>
      <PaginationBar
        v-if="photoTotal > perPage"
        :page="page"
        :total="photoTotal"
        :per-page="perPage"
        @update:page="setPage"
      />
    </template>

    <ul v-else-if="months.length > 0" class="timeline__months">
      <li v-for="entry in months" :key="`${entry.year}-${entry.month}`">
        <RouterLink :to="monthLink(entry.year, entry.month)" class="timeline__month-link">
          <span>{{ monthHeading(entry) }}</span>
          <span class="timeline__count">{{ entry.photoCount }}</span>
        </RouterLink>
      </li>
    </ul>
    <p v-else class="timeline__empty">Nenhuma foto com data disponível.</p>
  </section>
</template>

<style scoped>
.timeline__intro,
.timeline__empty {
  color: var(--muted, #888);
}

.timeline__back {
  margin: 0 0 0.75rem;
}

.timeline__back a {
  color: var(--muted, #888);
  text-decoration: none;
}

.timeline__months {
  list-style: none;
  margin: 1.5rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}

.timeline__month-link {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border-radius: 8px;
  background: #1a1a1a;
  color: inherit;
  text-decoration: none;
}

.timeline__month-link:hover {
  background: #262626;
}

.timeline__count {
  color: var(--muted, #888);
}
</style>
