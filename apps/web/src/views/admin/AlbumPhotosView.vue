<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Pencil, RefreshCw } from '@lucide/vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ApiError, adminApi, photoDisplayUrl } from '../../api/client'
import type {
  AdminAlbum,
  AdminAlbumDetail,
  AdminPhotoSummary,
  FacesStatus,
  MediaStatus,
  ReprocessScope,
  TagsStatus,
} from '../../api/types'
import AlbumFormDialog from '../../components/admin/AlbumFormDialog.vue'
import PaginationBar from '../../components/PaginationBar.vue'

const props = defineProps<{ albumId: string }>()
const route = useRoute()
const router = useRouter()

const photos = ref<AdminPhotoSummary[]>([])
const children = ref<AdminAlbum[]>([])
const albumMeta = ref<AdminAlbumDetail | null>(null)
const rootAlbums = ref<AdminAlbum[]>([])
const childrenTotal = ref(0)
const photosTotal = ref(0)
const childrenPerPage = 24
const photosPerPage = 48
const coverPhotoId = ref<string | null>(null)
const editingId = ref<string | 'new' | null>(null)
const deletingAlbum = ref(false)
const deletingAlbumInProgress = ref(false)
const loading = ref(true)
const error = ref<string | null>(null)
const coverSaving = ref(false)
const uploadingCount = ref(0)
const uploadError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const selectedIds = ref<Set<string>>(new Set())
const deleting = ref(false)
const deleteTarget = ref<'selected' | null>(null)

const childrenPage = computed(() => {
  const raw = Number(route.query.childrenPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const photosPage = computed(() => {
  const raw = Number(route.query.photosPage ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const MEDIA_LABEL: Record<MediaStatus, string> = {
  pending: 'Mídia pendente',
  converting: 'Convertendo',
  done: 'Mídia concluída',
  failed: 'Falha na mídia',
}

const FACE_LABEL: Record<FacesStatus, string> = {
  pending: 'Rostos pendentes',
  detecting: 'Detectando rostos',
  done: 'Rostos concluídos',
  failed: 'Falha nos rostos',
}

const TAG_LABEL: Record<TagsStatus, string> = {
  pending: 'Tags pendentes',
  detecting: 'Sugerindo tags',
  done: 'Tags concluídas',
  failed: 'Falha nas tags',
}

function isInFlight(p: AdminPhotoSummary): boolean {
  return (
    p.mediaStatus === 'pending' ||
    p.mediaStatus === 'converting' ||
    p.facesStatus === 'detecting' ||
    p.tagsStatus === 'detecting'
  )
}

const inFlight = computed(() => photos.value.some(isInFlight))

const allSelected = computed(
  () => photos.value.length > 0 && photos.value.every((p) => selectedIds.value.has(p.id)),
)

const selectedCount = computed(() => selectedIds.value.size)

let pollTimer: ReturnType<typeof setInterval> | null = null

function thumbSrc(photo: AdminPhotoSummary): string | null {
  return photoDisplayUrl(photo)
}

function albumCoverSrc(album: AdminAlbum): string | null {
  return album.cover ? photoDisplayUrl(album.cover) : null
}

function previewPlaceholder(photo: AdminPhotoSummary): string {
  if (photo.mediaStatus === 'pending' || photo.mediaStatus === 'converting') {
    return 'Convertendo…'
  }
  if (photo.mediaStatus === 'failed') {
    return 'Falha na conversão'
  }
  return 'Sem prévia'
}

const backLink = computed(() => {
  if (albumMeta.value?.parentId) {
    return { name: 'admin-album-photos' as const, params: { albumId: albumMeta.value.parentId } }
  }
  return { name: 'admin-albums' as const }
})

const backLabel = computed(() => (albumMeta.value?.parentId ? '← Álbum pai' : '← Álbuns'))

const formDialogOpen = computed({
  get: () => editingId.value !== null,
  set: (open: boolean) => {
    if (!open) editingId.value = null
  },
})

/** Roots for parent select, plus current non-root parent so the value still displays. */
const parentSelectOptions = computed(() => {
  const roots = rootAlbums.value.map((album) => ({ id: album.id, title: album.title }))
  const currentParentId = albumMeta.value?.parentId
  if (!currentParentId) return roots
  if (roots.some((album) => album.id === currentParentId)) return roots
  const parentSummary = albumMeta.value?.parent
  if (parentSummary && parentSummary.id === currentParentId) {
    return [...roots, { id: parentSummary.id, title: parentSummary.title }]
  }
  return roots
})

function startCreateChild() {
  editingId.value = 'new'
}

async function startEditCurrent() {
  if (!albumMeta.value) return
  try {
    const result = await adminApi.listAlbums({ page: 1, perPage: 100 })
    rootAlbums.value = result.data
  } catch {
    rootAlbums.value = []
  }
  editingId.value = albumMeta.value.id
}

function requestDeleteAlbum() {
  deletingAlbum.value = true
}

function closeDeleteAlbumDialog() {
  if (!deletingAlbumInProgress.value) {
    deletingAlbum.value = false
  }
}

async function confirmDeleteAlbum() {
  if (!albumMeta.value || deletingAlbumInProgress.value) return
  deletingAlbumInProgress.value = true
  error.value = null
  try {
    const parentId = albumMeta.value.parentId
    await adminApi.deleteAlbum(albumMeta.value.id)
    deletingAlbum.value = false
    if (parentId) {
      await router.push({ name: 'admin-album-photos', params: { albumId: parentId } })
    } else {
      await router.push({ name: 'admin-albums' })
    }
  } catch {
    error.value = 'Falha ao excluir álbum.'
  } finally {
    deletingAlbumInProgress.value = false
  }
}

function setChildrenPage(next: number) {
  router.push({
    name: 'admin-album-photos',
    params: { albumId: props.albumId },
    query: {
      ...route.query,
      ...(next > 1 ? { childrenPage: String(next) } : { childrenPage: undefined }),
    },
  })
}

function setPhotosPage(next: number) {
  router.push({
    name: 'admin-album-photos',
    params: { albumId: props.albumId },
    query: {
      ...route.query,
      ...(next > 1 ? { photosPage: String(next) } : { photosPage: undefined }),
    },
  })
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [album, childrenPageResult, photosPageResult] = await Promise.all([
      adminApi.getAlbum(props.albumId),
      adminApi.listAlbumChildren(props.albumId, { page: childrenPage.value, perPage: childrenPerPage }),
      adminApi.listAlbumPhotos(props.albumId, { page: photosPage.value, perPage: photosPerPage }),
    ])
    albumMeta.value = album
    children.value = childrenPageResult.data
    childrenTotal.value = childrenPageResult.meta.total
    coverPhotoId.value = album.coverPhotoId
    photos.value = photosPageResult.data
    photosTotal.value = photosPageResult.meta.total
    const valid = new Set(photos.value.map((p) => p.id))
    selectedIds.value = new Set([...selectedIds.value].filter((id) => valid.has(id)))
  } catch {
    error.value = 'Falha ao carregar fotos deste álbum.'
  } finally {
    loading.value = false
  }
}

async function useChildAsCover(child: AdminAlbum) {
  const coverId = child.coverPhotoId ?? child.cover?.id ?? null
  if (coverSaving.value || !coverId || coverPhotoId.value === coverId) {
    return
  }
  coverSaving.value = true
  error.value = null
  try {
    const album = await adminApi.updateAlbum(props.albumId, { coverPhotoId: coverId })
    coverPhotoId.value = album.coverPhotoId
    if (albumMeta.value) {
      albumMeta.value = { ...albumMeta.value, coverPhotoId: album.coverPhotoId, cover: album.cover }
    }
  } catch (err) {
    error.value =
      err instanceof ApiError
        ? `Falha ao definir capa: ${err.message}`
        : `Falha ao usar capa de "${child.title}".`
  } finally {
    coverSaving.value = false
  }
}

async function setAsCover(photo: AdminPhotoSummary) {
  if (coverSaving.value || coverPhotoId.value === photo.id) {
    return
  }
  coverSaving.value = true
  error.value = null
  try {
    const album = await adminApi.updateAlbum(props.albumId, { coverPhotoId: photo.id })
    coverPhotoId.value = album.coverPhotoId
    if (albumMeta.value) {
      albumMeta.value = { ...albumMeta.value, coverPhotoId: album.coverPhotoId, cover: album.cover }
    }  } catch (err) {
    error.value =
      err instanceof ApiError
        ? `Falha ao definir capa: ${err.message}`
        : `Falha ao definir capa para "${photo.title ?? photo.id}".`
  } finally {
    coverSaving.value = false
  }
}

async function clearCover() {
  if (coverSaving.value || !coverPhotoId.value) {
    return
  }
  coverSaving.value = true
  error.value = null
  try {
    const album = await adminApi.updateAlbum(props.albumId, { coverPhotoId: null })
    coverPhotoId.value = album.coverPhotoId
    if (albumMeta.value) {
      albumMeta.value = { ...albumMeta.value, coverPhotoId: null, cover: null }
    }  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao remover capa: ${err.message}` : 'Falha ao remover capa.'
  } finally {
    coverSaving.value = false
  }
}

function schedulePoll() {
  if (pollTimer) {
    return
  }
  pollTimer = setInterval(async () => {
    if (!inFlight.value) {
      if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
      }
      return
    }
    try {
      const result = await adminApi.listAlbumPhotos(props.albumId, {
        page: photosPage.value,
        perPage: photosPerPage,
      })
      photos.value = result.data
      photosTotal.value = result.meta.total
    } catch {
      // Ignore transient polling failures; the next tick will retry.
    }
  }, 3000)
}

watch(inFlight, (active) => {
  if (active) {
    schedulePoll()
  }
})

watch(
  () => [props.albumId, childrenPage.value, photosPage.value] as const,
  async () => {
    editingId.value = null
    deletingAlbum.value = false
    selectedIds.value = new Set()
    deleteTarget.value = null
    uploadError.value = null
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
    await load()
    if (inFlight.value) {
      schedulePoll()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (pollTimer) {
    clearInterval(pollTimer)
  }
})

async function onFilesSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (files.length === 0) {
    return
  }

  uploadError.value = null
  uploadingCount.value = files.length

  for (const file of files) {
    try {
      const uploaded = await adminApi.uploadPhoto(props.albumId, file)
      photos.value = [uploaded, ...photos.value]
    } catch (err) {
      uploadError.value =
        err instanceof ApiError ? `Falha ao enviar "${file.name}": ${err.message}` : `Falha ao enviar "${file.name}".`
    } finally {
      uploadingCount.value -= 1
    }
  }

  if (fileInput.value) {
    fileInput.value.value = ''
  }
  schedulePoll()
}

const reprocessing = ref<Set<string>>(new Set())
const reprocessScope = ref<ReprocessScope>('all')
const reprocessingAlbum = ref(false)

const SCOPE_LABEL: Record<ReprocessScope, string> = {
  all: 'Rostos + tags',
  faces: 'Apenas rostos',
  tags: 'Apenas tags',
}

async function reprocessAlbum() {
  if (reprocessingAlbum.value || photos.value.length === 0) {
    return
  }
  reprocessingAlbum.value = true
  error.value = null
  try {
    const updated = await adminApi.reprocessAlbum(props.albumId, reprocessScope.value)
    const byId = new Map(updated.map((p) => [p.id, p]))
    photos.value = photos.value.map((p) => {
      const u = byId.get(p.id)
      return u
        ? {
            ...p,
            mediaStatus: u.mediaStatus,
            facesStatus: u.facesStatus,
            tagsStatus: u.tagsStatus,
            processingError: u.processingError,
          }
        : p
    })
    schedulePoll()
  } catch {
    error.value = 'Falha ao reprocessar este álbum.'
  } finally {
    reprocessingAlbum.value = false
  }
}

async function reprocess(photo: AdminPhotoSummary) {
  reprocessing.value.add(photo.id)
  try {
    const updated = await adminApi.reprocessPhoto(photo.id)
    photos.value = photos.value.map((p) =>
      p.id === photo.id
        ? {
            ...p,
            mediaStatus: updated.mediaStatus,
            facesStatus: updated.facesStatus,
            tagsStatus: updated.tagsStatus,
            processingError: updated.processingError,
          }
        : p,
    )
    schedulePoll()
  } catch {
    error.value = `Falha ao reprocessar "${photo.title ?? photo.id}".`
  } finally {
    reprocessing.value.delete(photo.id)
  }
}

function toggleSelect(id: string, checked: boolean) {
  const next = new Set(selectedIds.value)
  if (checked) {
    next.add(id)
  } else {
    next.delete(id)
  }
  selectedIds.value = next
}

function toggleSelectAll(checked: boolean) {
  selectedIds.value = checked ? new Set(photos.value.map((p) => p.id)) : new Set()
}

function requestDeleteSelected() {
  if (selectedIds.value.size > 0) {
    deleteTarget.value = 'selected'
  }
}

function closeDeleteDialog() {
  if (!deleting.value) {
    deleteTarget.value = null
  }
}

async function confirmDelete() {
  if (deleteTarget.value !== 'selected') return

  deleting.value = true
  error.value = null
  try {
    const ids = [...selectedIds.value]
    await adminApi.bulkDeletePhotos(ids)
    const removed = new Set(ids)
    photos.value = photos.value.filter((p) => !removed.has(p.id))
    selectedIds.value = new Set()
    if (coverPhotoId.value && removed.has(coverPhotoId.value)) {
      coverPhotoId.value = null
    }
    deleteTarget.value = null
  } catch {
    error.value = 'Falha ao excluir fotos selecionadas.'
  } finally {
    deleting.value = false
  }
}

function badgeVariantFor(status: string) {
  if (status === 'done') return 'default'
  if (status === 'failed') return 'destructive'
  if (status === 'pending') return 'secondary'
  return 'outline'
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <RouterLink :to="backLink" class="admin-back-link">{{ backLabel }}</RouterLink>
        <h1 v-if="albumMeta" class="mt-2 text-xl font-semibold tracking-tight">{{ albumMeta.title }}</h1>
      </div>
      <div v-if="albumMeta" class="flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="outline" @click="startEditCurrent">Editar álbum</Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          class="text-destructive hover:bg-destructive/10 hover:text-destructive"
          data-testid="delete-album"
          @click="requestDeleteAlbum"
        >
          Excluir álbum
        </Button>
        <Button type="button" size="sm" class="h-9 px-4" data-testid="new-subalbum" @click="startCreateChild">
          Novo álbum
        </Button>
      </div>
    </div>

    <Card class="admin-panel rounded-2xl border shadow-none">
      <CardHeader class="pb-3">
        <CardTitle class="text-base font-semibold">Enviar</CardTitle>
        <CardDescription class="text-sm">JPEG, PNG ou WebP — vários arquivos de uma vez.</CardDescription>
      </CardHeader>
      <CardContent>
        <label class="admin-upload-zone flex cursor-pointer flex-col items-center gap-2 rounded-xl px-6 py-12 text-center">
          <span class="text-sm font-medium text-foreground">Escolha arquivos ou arraste aqui</span>
          <span class="text-xs text-muted-foreground">Convertido para AVIF automaticamente</span>
          <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            class="sr-only"
            @change="onFilesSelected"
          />
        </label>
        <p v-if="uploadingCount > 0" class="mt-3 text-center text-sm text-muted-foreground">
          Enviando {{ uploadingCount }} arquivo(s)…
        </p>
        <Alert v-if="uploadError" class="mt-3" variant="destructive">
          <AlertDescription>{{ uploadError }}</AlertDescription>
        </Alert>
      </CardContent>
    </Card>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Carregando fotos…
    </div>

    <Alert v-else-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <template v-else>
      <div class="space-y-3">
        <h2 class="text-sm font-semibold text-foreground">Subálbuns</h2>
        <p v-if="children.length === 0" class="text-sm text-muted-foreground">
          Nenhum subálbum ainda. Crie um com Novo álbum acima.
        </p>
        <div v-else class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          <article
            v-for="child in children"
            :key="child.id"
            data-testid="subalbum-tile"
            class="admin-photo-tile group min-w-0 overflow-hidden rounded-2xl"
          >
            <RouterLink
              :to="{ name: 'admin-album-photos', params: { albumId: child.id } }"
              class="relative block aspect-square bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <img
                v-if="albumCoverSrc(child)"
                :src="albumCoverSrc(child)!"
                :alt="child.title"
                class="size-full object-cover transition duration-300 group-hover:scale-[1.03]"
              />
              <div
                v-else
                class="flex size-full items-center justify-center px-3 text-center text-3xl font-medium text-muted-foreground"
              >
                {{ child.title.slice(0, 1).toUpperCase() }}
              </div>
              <div
                class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 pb-2.5 pt-8"
              >
                <p class="truncate text-sm font-medium text-white">{{ child.title }}</p>
                <p class="truncate text-[11px] text-white/75">
                  {{ child.photoCount }} foto{{ child.photoCount === 1 ? '' : 's' }}
                </p>
              </div>
            </RouterLink>
            <div class="border-t border-border/60 px-3 py-2">
              <button
                type="button"
                class="admin-action-link"
                data-testid="use-child-cover"
                :disabled="coverSaving || deleting || !(child.coverPhotoId ?? child.cover?.id)"
                :title="
                  child.coverPhotoId ?? child.cover?.id
                    ? 'Usar a capa deste álbum como capa do pai'
                    : 'Este subálbum ainda não tem capa'
                "
                @click="useChildAsCover(child)"
              >
                Usar como capa
              </button>
            </div>
          </article>
        </div>
        <PaginationBar
          :page="childrenPage"
          :total="childrenTotal"
          :per-page="childrenPerPage"
          @update:page="setChildrenPage"
        />
      </div>

      <div class="admin-panel flex flex-col gap-3 rounded-xl p-4 sm:flex-row sm:items-center sm:justify-between">
        <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium">
          <Checkbox
            :model-value="allSelected"
            :disabled="photos.length === 0 || deleting"
            aria-label="Selecionar todas as fotos"
            @update:model-value="toggleSelectAll($event === true)"
          />
          Selecionar todas
          <span v-if="selectedCount > 0" class="text-muted-foreground">({{ selectedCount }})</span>
        </label>
        <div class="flex min-w-0 flex-wrap items-center gap-2">
          <Select
            :model-value="reprocessScope"
            :disabled="reprocessingAlbum || deleting"
            @update:model-value="(v) => (reprocessScope = (v ?? 'all') as ReprocessScope)"
          >
            <SelectTrigger size="sm" class="w-full max-w-[9.5rem] sm:w-36" data-testid="reprocess-scope">
              <SelectValue>{{ SCOPE_LABEL[reprocessScope] }}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Rostos + tags</SelectItem>
              <SelectItem value="faces">Apenas rostos</SelectItem>
              <SelectItem value="tags">Apenas tags</SelectItem>
            </SelectContent>
          </Select>
          <Button
            type="button"
            variant="outline"
            size="sm"
            class="shrink-0 px-2.5 sm:px-3"
            :disabled="reprocessingAlbum || deleting || photos.length === 0"
            data-testid="reprocess-album"
            :aria-label="reprocessingAlbum ? 'Reprocessando…' : 'Reprocessar álbum'"
            :title="reprocessingAlbum ? 'Reprocessando…' : 'Reprocessar álbum'"
            @click="reprocessAlbum"
          >
            <RefreshCw class="size-3.5 shrink-0" :class="{ 'animate-spin': reprocessingAlbum }" />
            <span class="hidden truncate sm:inline">{{
              reprocessingAlbum ? 'Reprocessando…' : 'Reprocessar álbum'
            }}</span>
          </Button>
          <Button
            v-if="coverPhotoId"
            type="button"
            variant="ghost"
            size="sm"
            :disabled="coverSaving || deleting"
            data-testid="clear-cover"
            @click="clearCover"
          >
            Remover capa
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            class="max-w-full"
            :disabled="selectedCount === 0 || deleting"
            @click="requestDeleteSelected"
          >
            <span class="truncate">{{
              deleting
                ? 'Excluindo…'
                : selectedCount > 0
                  ? `Excluir selecionadas (${selectedCount})`
                  : 'Excluir selecionadas'
            }}</span>
          </Button>
        </div>
      </div>

      <div
        v-if="photos.length === 0"
        class="admin-upload-zone rounded-xl p-16 text-center text-sm text-muted-foreground"
      >
        <template v-if="children.length > 0">
          Nenhuma foto neste álbum ainda — abra um subálbum acima ou envie aqui.
        </template>
        <template v-else>
          Nenhuma foto neste álbum ainda. Envie algumas acima.
        </template>
      </div>
      <div v-else class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        <article
          v-for="photo in photos"
          :key="photo.id"
          data-testid="photo-row"
          class="admin-photo-tile group min-w-0 overflow-hidden rounded-2xl"
          :class="{
            'admin-photo-tile--selected': selectedIds.has(photo.id),
            'admin-photo-tile--cover': coverPhotoId === photo.id,
          }"
        >
          <div class="relative aspect-square bg-muted">
            <img
              v-if="thumbSrc(photo)"
              :src="thumbSrc(photo)!"
              :alt="photo.title ?? 'Foto'"
              class="size-full object-cover transition duration-300 group-hover:scale-[1.03]"
            />
            <div
              v-else
              class="flex size-full items-center justify-center px-3 text-center text-xs text-muted-foreground"
            >
              {{ previewPlaceholder(photo) }}
            </div>

            <div class="absolute left-2 top-2 z-10">
              <Checkbox
                :model-value="selectedIds.has(photo.id)"
                :disabled="deleting"
                class="admin-photo-check"
                :aria-label="`Selecionar ${photo.title ?? 'foto'}`"
                @update:model-value="toggleSelect(photo.id, $event === true)"
              />
            </div>

            <span
              v-if="coverPhotoId === photo.id"
              class="absolute right-2 top-2 z-10 rounded bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background"
              data-testid="cover-badge"
            >
              Capa
            </span>

            <button
              v-else
              type="button"
              class="absolute inset-x-0 bottom-0 z-10 bg-background/85 py-1.5 text-center text-[11px] font-medium text-foreground opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              :disabled="coverSaving || deleting || !thumbSrc(photo)"
              data-testid="set-cover"
              @click="setAsCover(photo)"
            >
              Definir como capa
            </button>
          </div>

          <div class="space-y-2 p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ photo.title ?? '(sem título)' }}</p>
              <div class="mt-1.5 flex flex-wrap gap-1">
                <Badge data-testid="status-media" :variant="badgeVariantFor(photo.mediaStatus)" class="text-[10px]">
                  {{ MEDIA_LABEL[photo.mediaStatus] }}
                </Badge>
                <Badge data-testid="status-faces" :variant="badgeVariantFor(photo.facesStatus)" class="text-[10px]">
                  {{ FACE_LABEL[photo.facesStatus] }}
                </Badge>
                <Badge data-testid="status-tags" :variant="badgeVariantFor(photo.tagsStatus)" class="text-[10px]">
                  {{ TAG_LABEL[photo.tagsStatus] }}
                </Badge>
              </div>
            </div>
            <p
              v-if="photo.processingError"
              class="line-clamp-2 text-xs text-destructive"
            >
              {{ photo.processingError }}
            </p>
            <div class="admin-photo-actions">
              <Button
                as-child
                size="icon-xs"
                variant="outline"
                class="admin-photo-action"
                :title="'Editar'"
              >
                <RouterLink
                  :to="{ name: 'admin-photo-edit', params: { id: photo.id } }"
                  :aria-label="`Editar ${photo.title ?? 'foto'}`"
                >
                  <Pencil />
                </RouterLink>
              </Button>
              <Button
                type="button"
                size="icon-xs"
                variant="outline"
                class="admin-photo-action admin-btn-retry"
                :disabled="reprocessing.has(photo.id) || deleting"
                :aria-label="reprocessing.has(photo.id) ? 'Reprocessando' : 'Tentar de novo'"
                :title="reprocessing.has(photo.id) ? 'Reprocessando…' : 'Tentar de novo'"
                data-testid="reprocess-photo"
                @click="reprocess(photo)"
              >
                <RefreshCw :class="{ 'animate-spin': reprocessing.has(photo.id) }" />
              </Button>
            </div>
          </div>
        </article>
      </div>
      <PaginationBar
        :page="photosPage"
        :total="photosTotal"
        :per-page="photosPerPage"
        @update:page="setPhotosPage"
      />
    </template>

    <AlbumFormDialog
      v-model:open="formDialogOpen"
      :editing-id="editingId"
      :album="editingId === 'new' ? null : albumMeta"
      :create-parent-id="props.albumId"
      :show-parent-select="editingId !== 'new'"
      :parent-options="parentSelectOptions"
      @saved="load"
    />

    <Dialog :open="deletingAlbum" @update:open="(open) => { if (!open) closeDeleteAlbumDialog() }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Excluir álbum?</DialogTitle>
          <DialogDescription>
            Excluir “{{ albumMeta?.title }}” e todos os seus subálbuns e fotos? Esta ação não pode ser desfeita.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button
            type="button"
            variant="outline"
            :disabled="deletingAlbumInProgress"
            @click="closeDeleteAlbumDialog"
          >
            Cancelar
          </Button>
          <Button
            type="button"
            variant="destructive"
            class="admin-btn-danger-solid"
            :disabled="deletingAlbumInProgress"
            @click="confirmDeleteAlbum"
          >
            {{ deletingAlbumInProgress ? 'Excluindo…' : 'Excluir álbum' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="deleteTarget !== null" @update:open="(open) => { if (!open) closeDeleteDialog() }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Excluir fotos selecionadas?</DialogTitle>
          <DialogDescription>
            Excluir {{ selectedCount }} fotos selecionadas? Esta ação não pode ser desfeita.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button type="button" variant="outline" :disabled="deleting" @click="closeDeleteDialog">Cancelar</Button>
          <Button type="button" variant="destructive" class="admin-btn-danger-solid" :disabled="deleting" @click="confirmDelete">
            {{ deleting ? 'Excluindo…' : 'Excluir fotos' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
