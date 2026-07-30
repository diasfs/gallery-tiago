<script setup lang="ts">
import { computed } from 'vue'
import { Eye } from '@lucide/vue'

const props = defineProps<{
  count: number
}>()

const label = computed(() => {
  const n = props.count
  return n === 1 ? '1 visualização' : `${n} visualizações`
})
</script>

<template>
  <span class="view-count" :aria-label="label" :title="label" data-testid="view-count">
    <Eye class="view-count__icon" :size="14" aria-hidden="true" />
    <span class="view-count__number">{{ count }}</span>
  </span>
</template>

<style scoped>
.view-count {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
  line-height: 1;
  color: inherit;
}

.view-count__icon {
  flex-shrink: 0;
  opacity: 0.9;
}

.view-count__number {
  font-variant-numeric: tabular-nums;
}
</style>
