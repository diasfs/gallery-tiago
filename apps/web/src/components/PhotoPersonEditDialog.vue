<script setup lang="ts">
import { computed } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { PersonSummary } from '../api/types'
import PersonAdminPanel from './PersonAdminPanel.vue'

const props = defineProps<{
  open: boolean
  person: PersonSummary | null
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  named: [payload: { id: string; name: string | null }]
  merged: [payload: { survivorId: string }]
}>()

const personLabel = computed(() => props.person?.name?.trim() || 'Sem nome')

function close() {
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => { if (!value) close() }">
    <DialogContent class="sm:max-w-lg" data-testid="person-edit-dialog">
      <DialogHeader>
        <DialogTitle>Editar {{ personLabel }}</DialogTitle>
      </DialogHeader>

      <PersonAdminPanel
        v-if="person"
        :person-id="person.id"
        @named="emit('named', $event)"
        @merged="emit('merged', $event)"
      />
    </DialogContent>
  </Dialog>
</template>
