<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError, adminApi } from '../../api/client'
import type { AdminPerson } from '../../api/types'
import { useAdminPersonSearch } from '../../composables/useAdminPersonSearch'

const props = defineProps<{
  open: boolean
  photoId?: string
  personId?: string
  face: { id: string; personId: string | null } | null
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  changed: []
  error: [message: string]
}>()

const {
  query,
  results,
  loading,
  search,
  clear,
} = useAdminPersonSearch(() => props.face?.personId ?? undefined)

const busy = ref(false)
const searchOpen = ref(false)
const searchRoot = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(
  () => props.open,
  (open) => {
    if (open) {
      clear()
      searchOpen.value = false
    }
  },
)

function close() {
  if (!busy.value) {
    emit('update:open', false)
  }
}

function onSearchInput(event: Event) {
  query.value = (event.target as HTMLInputElement).value
  searchOpen.value = true
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void search(), 200)
}

function onSearchFocus() {
  searchOpen.value = true
  if (searchTimer) clearTimeout(searchTimer)
  void search()
}

function onDocumentPointerDown(event: Event) {
  const root = searchRoot.value
  if (root && !root.contains(event.target as Node)) {
    searchOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})
onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})

async function assign(payload: { personId?: string; name?: string }) {
  if (!props.face) {
    return
  }
  busy.value = true
  try {
    if (props.photoId) {
      await adminApi.reassignPhotoFace(props.photoId, props.face.id, payload)
    } else if (props.personId) {
      await adminApi.reassignPersonFace(props.personId, props.face.id, payload)
    } else {
      throw new Error('photoId or personId is required')
    }
    clear()
    searchOpen.value = false
    emit('changed')
    emit('update:open', false)
  } catch (err) {
    emit(
      'error',
      err instanceof ApiError ? `Falha ao vincular rosto: ${err.message}` : 'Falha ao vincular rosto.',
    )
  } finally {
    busy.value = false
  }
}

function selectPerson(person: AdminPerson) {
  void assign({ personId: person.id })
}

function createAndAssign() {
  const name = query.value.trim()
  if (name === '') {
    return
  }
  void assign({ name })
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => !value && close()">
    <DialogContent data-testid="face-reassign-dialog" class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Vincular rosto</DialogTitle>
        <DialogDescription>
          Busque uma pessoa nomeada ou crie uma nova para este rosto.
        </DialogDescription>
      </DialogHeader>

      <div class="grid gap-2">
        <Label for="face-reassign-search" class="admin-label-sentence">Pessoa</Label>
        <div ref="searchRoot" class="relative">
          <Input
            id="face-reassign-search"
            v-model="query"
            type="search"
            placeholder="Buscar ou criar pelo nome…"
            autocomplete="off"
            data-testid="face-reassign-search"
            :disabled="busy"
            @focus="onSearchFocus"
            @input="onSearchInput"
            @keydown.esc="searchOpen = false"
          />
          <ul
            v-if="searchOpen && results.length > 0"
            class="admin-suggestions"
            data-testid="face-reassign-suggestions"
          >
            <li v-for="person in results" :key="person.id">
              <button
                type="button"
                class="admin-suggestion"
                data-testid="face-reassign-suggestion"
                :disabled="busy"
                @click="selectPerson(person)"
              >
                {{ person.name }}
              </button>
            </li>
          </ul>
        </div>
        <p v-if="loading" class="text-xs text-muted-foreground">Buscando…</p>
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" :disabled="busy" @click="close">Cancelar</Button>
        <Button
          type="button"
          :disabled="busy || query.trim() === ''"
          data-testid="face-reassign-create"
          @click="createAndAssign"
        >
          Adicionar / criar
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
