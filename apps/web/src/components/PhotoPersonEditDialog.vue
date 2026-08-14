<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import type { PersonSummary } from '../api/types'
import type { PersonMergedPayload } from '../lib/personMerge'
import PersonAdminPanel from './PersonAdminPanel.vue'

const props = defineProps<{
  open: boolean
  person: PersonSummary | null
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  named: [payload: { id: string; name: string | null }]
  merged: [payload: PersonMergedPayload]
}>()

const personLabel = computed(() => props.person?.name?.trim() || 'Sem nome')

function close() {
  emit('update:open', false)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.open) {
    event.preventDefault()
    close()
  }
}

watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
  },
)

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="public-dialog"
      data-testid="person-edit-dialog"
      role="dialog"
      aria-modal="true"
      :aria-label="`Editar ${personLabel}`"
    >
      <button type="button" class="public-dialog__backdrop" aria-label="Fechar" @click="close" />
      <div class="public-dialog__panel">
        <div class="public-dialog__header">
          <h2 class="public-dialog__title">Editar {{ personLabel }}</h2>
          <button type="button" class="public-dialog__close" aria-label="Fechar" @click="close">
            ×
          </button>
        </div>
        <PersonAdminPanel
          v-if="person"
          :person-id="person.id"
          flush
          @named="emit('named', $event)"
          @merged="emit('merged', $event)"
        />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.public-dialog {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: grid;
  place-items: center;
  padding: 1rem;
}

.public-dialog__backdrop {
  position: absolute;
  inset: 0;
  border: 0;
  padding: 0;
  margin: 0;
  background: rgba(0, 0, 0, 0.65);
  cursor: pointer;
}

.public-dialog__panel {
  position: relative;
  z-index: 1;
  width: min(100%, 32rem);
  max-height: min(90vh, 40rem);
  overflow: auto;
  padding: 1rem 1.1rem 1.1rem;
  border-radius: 10px;
  background: #1a1a1a;
  color: inherit;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45);
}

.public-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
}

.public-dialog__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  line-height: 1.3;
}

.public-dialog__close {
  flex-shrink: 0;
  width: 1.75rem;
  height: 1.75rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--muted, #888);
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
}

.public-dialog__close:hover {
  color: inherit;
  background: #2a2a2a;
}
</style>
