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
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPhotoSummary, ProcessingStatus } from '../../api/types'

const props = defineProps<{ albumId: string }>()

const photos = ref<AdminPhotoSummary[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const uploadingCount = ref(0)
const uploadError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const selectedIds = ref<Set<string>>(new Set())
const deleting = ref(false)
const deleteTarget = ref<AdminPhotoSummary | 'selected' | null>(null)

const STATUS_LABEL: Record<ProcessingStatus, string> = {
  pending: 'Pending',
  converting: 'Converting',
  detecting: 'Detecting faces',
  done: 'Done',
  failed: 'Failed',
}

const inFlight = computed(() =>
  photos.value.some((p) => p.processingStatus === 'pending' || p.processingStatus === 'converting' || p.processingStatus === 'detecting'),
)

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
    photos.value = await adminApi.listAlbumPhotos(props.albumId)
    const valid = new Set(photos.value.map((p) => p.id))
    selectedIds.value = new Set([...selectedIds.value].filter((id) => valid.has(id)))
  } catch {
    error.value = 'Failed to load photos for this album.'
  } finally {
    loading.value = false
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

async function reprocess(photo: AdminPhotoSummary) {
  reprocessing.value.add(photo.id)
  try {
    const updated = await adminApi.reprocessPhoto(photo.id)
    photos.value = photos.value.map((p) =>
      p.id === photo.id
        ? { ...p, processingStatus: updated.processingStatus, processingError: updated.processingError }
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
    } else {
      await adminApi.deletePhoto(target.id)
      photos.value = photos.value.filter((p) => p.id !== target.id)
      selectedIds.value.delete(target.id)
      selectedIds.value = new Set(selectedIds.value)
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

function badgeVariant(status: ProcessingStatus) {
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
          :class="{ 'admin-photo-tile--selected': selectedIds.has(photo.id) }"
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
          </div>

          <div class="space-y-2 p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ photo.title ?? '(untitled)' }}</p>
              <Badge :variant="badgeVariant(photo.processingStatus)" class="mt-1.5 text-[10px]">
                {{ STATUS_LABEL[photo.processingStatus] }}
              </Badge>
            </div>
            <p
              v-if="photo.processingStatus === 'failed' && photo.processingError"
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
