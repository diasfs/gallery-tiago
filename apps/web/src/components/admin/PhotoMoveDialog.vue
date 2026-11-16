<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError, adminApi } from '../../api/client'
import { useAdminAlbumParentSearch, type AlbumParentOption } from '../../composables/useAdminAlbumParentSearch'

const props = defineProps<{
  open: boolean
  photoIds: string[]
  excludeAlbumId?: string
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  moved: []
}>()

const { query, results, loading, search, clear } = useAdminAlbumParentSearch()
const selected = ref<AlbumParentOption | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const searchOpen = ref(false)
const searchRoot = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const visibleResults = computed(() =>
  results.value.filter((album) => album.id !== props.excludeAlbumId),
)

watch(
  () => props.open,
  (open) => {
    if (open) {
      clear()
      selected.value = null
      error.value = null
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

function selectAlbum(album: AlbumParentOption) {
  selected.value = album
  query.value = album.title
  searchOpen.value = false
}

async function confirm() {
  if (!selected.value || props.photoIds.length === 0) {
    return
  }
  busy.value = true
  error.value = null
  try {
    await adminApi.bulkMovePhotos(props.photoIds, selected.value.id)
    emit('moved')
    emit('update:open', false)
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao mover: ${err.message}` : 'Falha ao mover fotos.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => !value && close()">
    <DialogContent data-testid="photo-move-dialog" class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Mover fotos</DialogTitle>
        <DialogDescription>
          Mover {{ photoIds.length }} {{ photoIds.length === 1 ? 'foto' : 'fotos' }} para outro álbum.
        </DialogDescription>
      </DialogHeader>

      <div class="grid gap-2">
        <Label for="photo-move-search">Álbum destino</Label>
        <div ref="searchRoot" class="relative">
          <Input
            id="photo-move-search"
            v-model="query"
            type="search"
            placeholder="Buscar álbum…"
            autocomplete="off"
            data-testid="photo-move-search"
            :disabled="busy"
            @focus="onSearchFocus"
            @input="onSearchInput"
            @keydown.esc="searchOpen = false"
          />
          <ul
            v-if="searchOpen && visibleResults.length > 0"
            class="admin-suggestions"
            data-testid="photo-move-suggestions"
          >
            <li v-for="album in visibleResults" :key="album.id">
              <button
                type="button"
                class="admin-suggestion"
                data-testid="photo-move-suggestion"
                :disabled="busy"
                @click="selectAlbum(album)"
              >
                {{ album.title }}
              </button>
            </li>
          </ul>
        </div>
        <p v-if="loading" class="text-xs text-muted-foreground">Buscando…</p>
      </div>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <DialogFooter>
        <Button type="button" variant="outline" :disabled="busy" @click="close">Cancelar</Button>
        <Button
          type="button"
          :disabled="busy || selected === null"
          data-testid="photo-move-confirm"
          @click="confirm"
        >
          {{ busy ? 'Movendo…' : 'Mover' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
