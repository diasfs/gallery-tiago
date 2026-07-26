<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { RefreshCw } from '@lucide/vue'
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
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPhotoSummary, FacesStatus, MediaStatus, ReprocessScope, TagsStatus } from '../../api/types'

const props = defineProps<{ albumId: string }>()

const photos = ref<AdminPhotoSummary[]>([])
const coverPhotoId = ref<string | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const coverSaving = ref(false)
const uploadingCount = ref(0)
const uploadError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const selectedIds = ref<Set<string>>(new Set())
const deleting = ref(false)
const deleteTarget = ref<AdminPhotoSummary | 'selected' | null>(null)

const MEDIA_LABEL: Record<MediaStatus, string> = {
  pending: 'Media pending',
  converting: 'Converting',
  done: 'Media done',
  failed: 'Media failed',
}

const FACE_LABEL: Record<FacesStatus, string> = {
  pending: 'Faces pending',
  detecting: 'Detecting faces',
  done: 'Faces done',
  failed: 'Faces failed',
}

const TAG_LABEL: Record<TagsStatus, string> = {
  pending: 'Tags pending',
  detecting: 'Suggesting tags',
  done: 'Tags done',
  failed: 'Tags failed',
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
  const thumb = Object.values(photo.thumbPaths ?? {})[0]
  return mediaUrl(thumb ?? photo.avifPath)
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [album, albumPhotos] = await Promise.all([
      adminApi.getAlbum(props.albumId),
      adminApi.listAlbumPhotos(props.albumId),
    ])
    coverPhotoId.value = album.coverPhotoId
    photos.value = albumPhotos
    const valid = new Set(photos.value.map((p) => p.id))
    selectedIds.value = new Set([...selectedIds.value].filter((id) => valid.has(id)))
  } catch {
    error.value = 'Failed to load photos for this album.'
  } finally {
    loading.value = false
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
  } catch (err) {
    error.value =
      err instanceof ApiError
        ? `Failed to set cover: ${err.message}`
        : `Failed to set cover for "${photo.title ?? photo.id}".`
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
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to clear cover: ${err.message}` : 'Failed to clear cover.'
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
      photos.value = await adminApi.listAlbumPhotos(props.albumId)
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

onMounted(async () => {
  await load()
  if (inFlight.value) {
    schedulePoll()
  }
})

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
        err instanceof ApiError ? `Failed to upload "${file.name}": ${err.message}` : `Failed to upload "${file.name}".`
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
  all: 'Faces + tags',
  faces: 'Faces only',
  tags: 'Tags only',
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
    error.value = 'Failed to reprocess this album.'
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
    error.value = `Failed to reprocess "${photo.title ?? photo.id}".`
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

function requestDeleteOne(photo: AdminPhotoSummary) {
  deleteTarget.value = photo
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
  const target = deleteTarget.value
  if (!target) return

  deleting.value = true
  error.value = null
  try {
    if (target === 'selected') {
      const ids = [...selectedIds.value]
      await adminApi.bulkDeletePhotos(ids)
      const removed = new Set(ids)
      photos.value = photos.value.filter((p) => !removed.has(p.id))
      selectedIds.value = new Set()
      if (coverPhotoId.value && removed.has(coverPhotoId.value)) {
        coverPhotoId.value = null
      }
    } else {
      await adminApi.deletePhoto(target.id)
      photos.value = photos.value.filter((p) => p.id !== target.id)
      selectedIds.value.delete(target.id)
      selectedIds.value = new Set(selectedIds.value)
      if (coverPhotoId.value === target.id) {
        coverPhotoId.value = null
      }
    }
    deleteTarget.value = null
  } catch {
    error.value =
      target === 'selected'
        ? 'Failed to delete selected photos.'
        : `Failed to delete "${target.title ?? target.id}".`
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
    <div>
      <RouterLink to="/admin" class="admin-back-link">← Albums</RouterLink>
    </div>

    <Card class="admin-panel rounded-2xl border shadow-none">
      <CardHeader class="pb-3">
        <CardTitle class="text-base font-semibold">Upload</CardTitle>
        <CardDescription class="text-sm">JPEG, PNG, or WebP — multiple files supported.</CardDescription>
      </CardHeader>
      <CardContent>
        <label class="admin-upload-zone flex cursor-pointer flex-col items-center gap-2 rounded-xl px-6 py-12 text-center">
          <span class="text-sm font-medium text-foreground">Choose files or drag here</span>
          <span class="text-xs text-muted-foreground">Converted to AVIF automatically</span>
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
          Uploading {{ uploadingCount }} file(s)…
        </p>
        <Alert v-if="uploadError" class="mt-3" variant="destructive">
          <AlertDescription>{{ uploadError }}</AlertDescription>
        </Alert>
      </CardContent>
    </Card>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Loading photos…
    </div>

    <Alert v-else-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <template v-else>
      <div class="admin-panel flex flex-col gap-3 rounded-xl p-4 sm:flex-row sm:items-center sm:justify-between">
        <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium">
          <Checkbox
            :model-value="allSelected"
            :disabled="photos.length === 0 || deleting"
            aria-label="Select all photos"
            @update:model-value="toggleSelectAll($event === true)"
          />
          Select all
          <span v-if="selectedCount > 0" class="text-muted-foreground">({{ selectedCount }})</span>
        </label>
        <div class="flex flex-wrap items-center gap-2">
          <Select
            :model-value="reprocessScope"
            :disabled="reprocessingAlbum || deleting"
            @update:model-value="(v) => (reprocessScope = (v ?? 'all') as ReprocessScope)"
          >
            <SelectTrigger size="sm" class="w-36" data-testid="reprocess-scope">
              <SelectValue>{{ SCOPE_LABEL[reprocessScope] }}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Faces + tags</SelectItem>
              <SelectItem value="faces">Faces only</SelectItem>
              <SelectItem value="tags">Tags only</SelectItem>
            </SelectContent>
          </Select>
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="reprocessingAlbum || deleting || photos.length === 0"
            data-testid="reprocess-album"
            @click="reprocessAlbum"
          >
            <RefreshCw class="size-3.5 shrink-0" :class="{ 'animate-spin': reprocessingAlbum }" />
            {{ reprocessingAlbum ? 'Reprocessing…' : 'Reprocess album' }}
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
            Clear cover
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            :disabled="selectedCount === 0 || deleting"
            @click="requestDeleteSelected"
          >
            {{ deleting ? 'Deleting…' : selectedCount > 0 ? `Delete selected (${selectedCount})` : 'Delete selected' }}
          </Button>
        </div>
      </div>

      <div
        v-if="photos.length === 0"
        class="admin-upload-zone rounded-xl p-16 text-center text-sm text-muted-foreground"
      >
        No photos in this album yet. Upload some above.
      </div>

      <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        <article
          v-for="photo in photos"
          :key="photo.id"
          data-testid="photo-row"
          class="admin-photo-tile group overflow-hidden rounded-2xl"
          :class="{
            'admin-photo-tile--selected': selectedIds.has(photo.id),
            'admin-photo-tile--cover': coverPhotoId === photo.id,
          }"
        >
          <div class="relative aspect-square bg-muted">
            <img
              v-if="thumbSrc(photo)"
              :src="thumbSrc(photo)!"
              :alt="photo.title ?? 'Photo'"
              class="size-full object-cover transition duration-300 group-hover:scale-[1.03]"
            />
            <div
              v-else
              class="flex size-full items-center justify-center px-3 text-center text-xs text-muted-foreground"
            >
              No preview
            </div>

            <div class="absolute left-2 top-2 z-10">
              <Checkbox
                :model-value="selectedIds.has(photo.id)"
                :disabled="deleting"
                class="admin-photo-check"
                :aria-label="`Select ${photo.title ?? 'photo'}`"
                @update:model-value="toggleSelect(photo.id, $event === true)"
              />
            </div>

            <span
              v-if="coverPhotoId === photo.id"
              class="absolute right-2 top-2 z-10 rounded bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background"
              data-testid="cover-badge"
            >
              Cover
            </span>

            <button
              v-else
              type="button"
              class="absolute inset-x-0 bottom-0 z-10 bg-background/85 py-1.5 text-center text-[11px] font-medium text-foreground opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              :disabled="coverSaving || deleting || !thumbSrc(photo)"
              data-testid="set-cover"
              @click="setAsCover(photo)"
            >
              Set as cover
            </button>
          </div>

          <div class="space-y-2 p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ photo.title ?? '(untitled)' }}</p>
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
              <Button as-child size="xs" variant="outline">
                <RouterLink :to="{ name: 'admin-photo-edit', params: { id: photo.id } }">Edit</RouterLink>
              </Button>
              <Button
                type="button"
                size="xs"
                variant="outline"
                class="admin-btn-retry"
                :disabled="reprocessing.has(photo.id) || deleting"
                @click="reprocess(photo)"
              >
                <RefreshCw class="size-3 shrink-0" :class="{ 'animate-spin': reprocessing.has(photo.id) }" />
                {{ reprocessing.has(photo.id) ? 'Retrying' : 'Retry' }}
              </Button>
              <Button
                type="button"
                size="xs"
                variant="destructive"
                :disabled="deleting"
                @click="requestDeleteOne(photo)"
              >
                Delete
              </Button>
            </div>
          </div>
        </article>
      </div>
    </template>

    <Dialog :open="deleteTarget !== null" @update:open="(open) => { if (!open) closeDeleteDialog() }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ deleteTarget === 'selected' ? 'Delete selected photos?' : 'Delete photo?' }}</DialogTitle>
          <DialogDescription v-if="deleteTarget === 'selected'">
            Delete {{ selectedCount }} selected photos? This cannot be undone.
          </DialogDescription>
          <DialogDescription v-else>
            Delete “{{ deleteTarget?.title ?? deleteTarget?.id }}”? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button type="button" variant="outline" :disabled="deleting" @click="closeDeleteDialog">Cancel</Button>
          <Button type="button" variant="destructive" class="admin-btn-danger-solid" :disabled="deleting" @click="confirmDelete">
            {{ deleting ? 'Deleting…' : deleteTarget === 'selected' ? 'Delete photos' : 'Delete photo' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
