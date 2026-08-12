<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const props = defineProps<{
  page: number
  total: number
  perPage: number
}>()

const emit = defineEmits<{
  'update:page': [page: number]
}>()

const totalPages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage) || 1))
const show = computed(() => props.total > props.perPage)
const jumpInput = ref('')

watch(
  () => props.page,
  (next) => {
    jumpInput.value = String(next)
  },
  { immediate: true },
)

type PageItem = { type: 'page'; page: number } | { type: 'ellipsis' }

function buildPageItems(current: number, last: number): PageItem[] {
  if (last <= 12) {
    return Array.from({ length: last }, (_, i) => ({ type: 'page' as const, page: i + 1 }))
  }

  const pages = new Set<number>([1, last, current, current - 1, current + 1, current - 2, current + 2])
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b)

  const items: PageItem[] = []
  let prev = 0
  for (const p of sorted) {
    if (prev > 0 && p - prev > 1) {
      items.push({ type: 'ellipsis' })
    }
    items.push({ type: 'page', page: p })
    prev = p
  }
  return items
}

const pageItems = computed(() => buildPageItems(props.page, totalPages.value))

function goTo(page: number) {
  const clamped = Math.min(totalPages.value, Math.max(1, page))
  if (clamped !== props.page) {
    emit('update:page', clamped)
  }
}

function submitJump() {
  const parsed = Number.parseInt(jumpInput.value, 10)
  if (!Number.isFinite(parsed)) {
    jumpInput.value = String(props.page)
    return
  }
  goTo(parsed)
}
</script>

<template>
  <div v-if="show" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-testid="pagination">
    <Button type="button" variant="outline" size="sm" :disabled="page <= 1" @click="goTo(page - 1)">
      Anterior
    </Button>

    <div class="flex flex-wrap items-center justify-center gap-1">
      <template v-for="(item, index) in pageItems" :key="`${item.type}-${index}`">
        <span v-if="item.type === 'ellipsis'" class="px-1 text-sm text-muted-foreground">…</span>
        <Button
          v-else
          type="button"
          size="sm"
          :variant="item.page === page ? 'default' : 'outline'"
          class="min-w-9 tabular-nums"
          :data-testid="`pagination-page-${item.page}`"
          @click="goTo(item.page)"
        >
          {{ item.page }}
        </Button>
      </template>
    </div>

    <div class="flex items-center justify-center gap-2 sm:justify-end">
      <form class="flex items-center gap-2" @submit.prevent="submitJump">
        <label class="sr-only" for="pagination-jump">Ir para página</label>
        <Input
          id="pagination-jump"
          v-model="jumpInput"
          type="number"
          min="1"
          :max="totalPages"
          class="h-8 w-16 tabular-nums"
          data-testid="pagination-jump"
        />
        <Button type="submit" variant="outline" size="sm" data-testid="pagination-jump-submit">Ir</Button>
      </form>
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page >= totalPages"
        @click="goTo(page + 1)"
      >
        Próxima
      </Button>
    </div>
  </div>
</template>
