<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { ApiError, adminApi, type AlbumWritePayload } from '../../api/client'
import type { AdminAlbum, AdminAlbumDetail, Location } from '../../api/types'
import { useAdminAlbumParentSearch } from '../../composables/useAdminAlbumParentSearch'
import LocationPicker from './LocationPicker.vue'
import { toDateInputValue } from '@/lib/utils'

const props = defineProps<{
  open: boolean
  /** `'new'` creates; otherwise updates this album id. */
  editingId: string | 'new' | null
  /** Album being edited (ignored for create). */
  album?: AdminAlbum | null
  /** Fixed parent for create (null = root). Ignored when editing. */
  createParentId?: string | null
  /** When true (edit only), show parent search. */
  showParentSelect?: boolean
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  saved: []
}>()

const formError = ref<string | null>(null)
const form = reactive<{
  title: string
  slug: string
  description: string
  visibility: 'public' | 'unlisted' | 'private'
  photosPerPage: number
  parentId: string
  takenAt: string
  takenAtEnd: string
}>({
  title: '',
  slug: '',
  description: '',
  visibility: 'private',
  photosPerPage: 48,
  parentId: '',
  takenAt: '',
  takenAtEnd: '',
})

const selectedLocation = ref<Location | null>(null)

const isCreate = computed(() => props.editingId === 'new')
const dialogOpen = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
})

const {
  query: parentQuery,
  results: parentResults,
  loading: parentLoading,
  error: parentSearchError,
  search: searchParentAlbums,
  clear: clearParentSearch,
} = useAdminAlbumParentSearch(() =>
  props.editingId && props.editingId !== 'new' ? props.editingId : undefined,
)
const parentSearchOpen = ref(false)
const parentSearchRoot = ref<HTMLElement | null>(null)
let parentSearchTimer: ReturnType<typeof setTimeout> | null = null

function resetForm() {
  form.title = ''
  form.slug = ''
  form.description = ''
  form.visibility = 'private'
  form.photosPerPage = 48
  form.parentId = ''
  form.takenAt = ''
  form.takenAtEnd = ''
  selectedLocation.value = null
  formError.value = null
  clearParentSearch()
  closeParentSearch()
}

function hydrateFromAlbum(album: AdminAlbum) {
  form.title = album.title
  form.slug = album.slug
  form.description = album.description ?? ''
  form.visibility = album.visibility
  form.photosPerPage = album.photosPerPage
  form.parentId = album.parentId ?? ''
  form.takenAt = album.takenAt ? toDateInputValue(album.takenAt) : ''
  form.takenAtEnd = album.takenAtEnd ? toDateInputValue(album.takenAtEnd) : ''
  selectedLocation.value = album.location

  const parent = (album as AdminAlbumDetail).parent
  parentQuery.value = form.parentId === '' ? '(raiz)' : (parent?.title ?? '')
}

watch(
  () => [props.open, props.editingId, props.album] as const,
  ([open]) => {
    if (!open || props.editingId == null) return
    resetForm()
    if (props.editingId === 'new') {
      form.parentId = props.createParentId ?? ''
    } else if (props.album) {
      hydrateFromAlbum(props.album)
    }
  },
)

function updateVisibility(value: unknown) {
  if (value === 'public' || value === 'unlisted' || value === 'private') {
    form.visibility = value
  }
}

function closeParentSearch() {
  parentSearchOpen.value = false
}

function onParentSearchInput(event: Event) {
  parentQuery.value = (event.target as HTMLInputElement).value
  parentSearchOpen.value = true
  if (parentSearchTimer) clearTimeout(parentSearchTimer)
  parentSearchTimer = setTimeout(() => void searchParentAlbums(), 200)
}

function onParentSearchFocus() {
  parentSearchOpen.value = true
  if (parentSearchTimer) clearTimeout(parentSearchTimer)
  void searchParentAlbums()
}

function selectRootParent() {
  form.parentId = ''
  parentQuery.value = '(raiz)'
  parentResults.value = []
  closeParentSearch()
}

function selectParentAlbum(option: { id: string; title: string }) {
  form.parentId = option.id
  parentQuery.value = option.title
  parentResults.value = []
  closeParentSearch()
}

function onDocumentPointerDown(event: Event) {
  const root = parentSearchRoot.value
  if (root && !root.contains(event.target as Node)) {
    closeParentSearch()
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})

onUnmounted(() => {
  if (parentSearchTimer) clearTimeout(parentSearchTimer)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})

async function submit() {
  formError.value = null
  if (!form.title.trim() || !form.slug.trim()) {
    formError.value = 'Título e slug são obrigatórios.'
    return
  }
  if (form.takenAtEnd && !form.takenAt) {
    formError.value = 'Informe a data inicial do período.'
    return
  }
  if (form.takenAt && form.takenAtEnd && form.takenAtEnd < form.takenAt) {
    formError.value = 'A data final deve ser igual ou posterior à inicial.'
    return
  }
  if (!Number.isInteger(form.photosPerPage) || form.photosPerPage < 1) {
    formError.value = 'Fotos por página deve ser um inteiro maior ou igual a 1.'
    return
  }

  const parentId = isCreate.value
    ? (props.createParentId ?? null)
    : form.parentId === ''
      ? null
      : form.parentId

  const payload: AlbumWritePayload = {
    title: form.title.trim(),
    slug: form.slug.trim(),
    description: form.description.trim() === '' ? null : form.description.trim(),
    visibility: form.visibility,
    photosPerPage: form.photosPerPage,
    parentId,
    takenAt: form.takenAt === '' ? null : new Date(`${form.takenAt}T00:00:00.000Z`).toISOString(),
    takenAtEnd:
      form.takenAtEnd === '' ? null : new Date(`${form.takenAtEnd}T00:00:00.000Z`).toISOString(),
    locationId: selectedLocation.value?.id ?? null,
  }

  try {
    if (props.editingId === 'new') {
      await adminApi.createAlbum(payload)
    } else if (props.editingId) {
      await adminApi.updateAlbum(props.editingId, payload)
    }
    emit('update:open', false)
    emit('saved')
  } catch (err) {
    formError.value = err instanceof ApiError ? `Falha ao salvar: ${err.message}` : 'Falha ao salvar.'
  }
}

function cancel() {
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="dialogOpen" @update:open="(v) => (dialogOpen = v)">
    <DialogContent class="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ isCreate ? 'Novo álbum' : 'Editar álbum' }}</DialogTitle>
        <DialogDescription>
          {{
            isCreate
              ? 'Crie uma coleção para suas fotos.'
              : 'Atualize os detalhes e a posição deste álbum.'
          }}
        </DialogDescription>
      </DialogHeader>

      <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
          <Label for="album-title">Título</Label>
          <Input id="album-title" v-model="form.title" required />
        </div>

        <div class="grid gap-2">
          <Label for="album-slug">Slug</Label>
          <Input id="album-slug" v-model="form.slug" required />
        </div>

        <div class="grid gap-2">
          <Label for="album-description">Descrição</Label>
          <Textarea id="album-description" v-model="form.description" rows="3" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-2">
            <Label for="album-taken-at">De</Label>
            <Input id="album-taken-at" v-model="form.takenAt" type="date" data-testid="album-taken-at" />
          </div>
          <div class="grid gap-2">
            <Label for="album-taken-at-end">Até</Label>
            <Input
              id="album-taken-at-end"
              v-model="form.takenAtEnd"
              type="date"
              data-testid="album-taken-at-end"
            />
          </div>
        </div>

        <LocationPicker v-model="selectedLocation" />

        <div class="grid gap-2">
          <Label for="album-visibility">Visibilidade</Label>
          <Select :model-value="form.visibility" @update:model-value="updateVisibility">
            <SelectTrigger id="album-visibility" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="public">Público</SelectItem>
              <SelectItem value="unlisted">Não listado</SelectItem>
              <SelectItem value="private">Privado</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="grid gap-2">
          <Label for="album-photos-per-page">Fotos por página</Label>
          <Input
            id="album-photos-per-page"
            v-model.number="form.photosPerPage"
            type="number"
            min="1"
            step="1"
            data-testid="album-photos-per-page"
          />
        </div>

        <div v-if="showParentSelect && !isCreate" class="grid gap-2">
          <Label for="album-parent-search">Álbum pai</Label>
          <div ref="parentSearchRoot" class="relative">
            <Input
              id="album-parent-search"
              v-model="parentQuery"
              type="search"
              placeholder="Buscar álbum pai…"
              autocomplete="off"
              data-testid="album-parent-search"
              @focus="onParentSearchFocus"
              @input="onParentSearchInput"
              @keydown.esc="closeParentSearch"
            />
            <ul
              v-if="parentSearchOpen && (parentResults.length > 0 || !parentLoading)"
              class="admin-suggestions"
              data-testid="album-parent-suggestions"
            >
              <li>
                <button
                  type="button"
                  class="admin-suggestion"
                  data-testid="album-parent-root"
                  @click="selectRootParent"
                >
                  (raiz)
                </button>
              </li>
              <li v-for="option in parentResults" :key="option.id">
                <button
                  type="button"
                  class="admin-suggestion"
                  data-testid="album-parent-suggestion"
                  @click="selectParentAlbum(option)"
                >
                  {{ option.title }}
                </button>
              </li>
            </ul>
          </div>
          <p class="text-xs text-muted-foreground">
            <span v-if="parentLoading">Buscando…</span>
            <span v-else>Digite para buscar. Escolha (raiz) para álbum de topo.</span>
          </p>
          <p v-if="parentSearchError" class="text-sm text-destructive">{{ parentSearchError }}</p>
        </div>

        <p v-if="isCreate" class="text-sm text-muted-foreground">
          Após salvar, adicione fotos e escolha uma capa na página Fotos do álbum.
        </p>

        <Alert v-if="formError" variant="destructive">
          <AlertDescription>{{ formError }}</AlertDescription>
        </Alert>

        <DialogFooter class="gap-2 sm:gap-2">
          <Button type="button" variant="outline" @click="cancel">Cancelar</Button>
          <Button type="submit">Salvar</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
