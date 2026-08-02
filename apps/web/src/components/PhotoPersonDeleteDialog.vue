<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ApiError, adminApi } from '../api/client'
import type { PersonSummary } from '../api/types'

const props = defineProps<{
  open: boolean
  person: PersonSummary | null
  photoId: string
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  done: []
}>()

const busy = ref(false)
const error = ref<string | null>(null)

const personLabel = computed(() => props.person?.name?.trim() || 'Sem nome')

watch(
  () => props.open,
  (open) => {
    if (open) {
      error.value = null
    }
  },
)

function close() {
  if (!busy.value) {
    emit('update:open', false)
  }
}

async function unlinkFromPhoto() {
  if (!props.person) {
    return
  }
  busy.value = true
  error.value = null
  try {
    await adminApi.removePersonFromPhoto(props.photoId, props.person.id)
    emit('update:open', false)
    emit('done')
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao remover vínculo: ${err.message}` : 'Falha ao remover vínculo.'
  } finally {
    busy.value = false
  }
}

async function discardPerson() {
  if (!props.person) {
    return
  }
  busy.value = true
  error.value = null
  try {
    await adminApi.discardPerson(props.person.id)
    emit('update:open', false)
    emit('done')
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao excluir pessoa: ${err.message}` : 'Falha ao excluir pessoa.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => { if (!value) close() }">
    <DialogContent class="sm:max-w-md" data-testid="person-delete-dialog">
      <DialogHeader>
        <DialogTitle>Remover {{ personLabel }}?</DialogTitle>
        <DialogDescription>
          Escolha se deseja remover apenas o vínculo desta pessoa com esta foto, ou excluir a pessoa do
          sistema. Excluir a pessoa remove todos os rostos em todas as fotos e não pode ser desfeito.
        </DialogDescription>
      </DialogHeader>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <DialogFooter class="gap-2 sm:gap-2 sm:flex-col sm:items-stretch">
        <Button
          type="button"
          variant="outline"
          :disabled="busy"
          data-testid="person-delete-cancel"
          @click="close"
        >
          Cancelar
        </Button>
        <Button
          type="button"
          variant="secondary"
          :disabled="busy"
          data-testid="person-delete-unlink"
          @click="unlinkFromPhoto"
        >
          {{ busy ? 'Removendo…' : 'Remover desta foto' }}
        </Button>
        <Button
          type="button"
          variant="destructive"
          class="admin-btn-danger-solid"
          :disabled="busy"
          data-testid="person-delete-discard"
          @click="discardPerson"
        >
          {{ busy ? 'Excluindo…' : 'Excluir pessoa' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
