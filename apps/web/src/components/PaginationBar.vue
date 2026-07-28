<script setup lang="ts">
import { computed } from 'vue'
import { Button } from '@/components/ui/button'

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
</script>

<template>
  <div v-if="show" class="flex items-center justify-between gap-3" data-testid="pagination">
    <Button type="button" variant="outline" size="sm" :disabled="page <= 1" @click="emit('update:page', page - 1)">
      Anterior
    </Button>
    <span class="text-sm text-muted-foreground">Página {{ page }} / {{ totalPages }}</span>
    <Button
      type="button"
      variant="outline"
      size="sm"
      :disabled="page >= totalPages"
      @click="emit('update:page', page + 1)"
    >
      Próxima
    </Button>
  </div>
</template>
