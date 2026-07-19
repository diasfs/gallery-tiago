<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPhotoSummary, ProcessingStatus } from '../../api/types'

const props = defineProps<{ albumId: string }>()

const photos = ref<AdminPhotoSummary[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const uploadingCount = ref(0)
const uploadError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

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
</script>

<template>
  <section class="album-photos">
    <header class="album-photos__header">
      <h1>Photos</h1>
      <RouterLink to="/admin">← Back to albums</RouterLink>
    </header>

    <div class="upload-box">
      <label class="upload-box__label">
        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple @change="onFilesSelected" />
        <span>Choose files to upload…</span>
      </label>
      <p v-if="uploadingCount > 0" class="upload-box__status">Uploading {{ uploadingCount }} file(s)…</p>
      <p v-if="uploadError" class="album-photos__error">{{ uploadError }}</p>
    </div>

    <p v-if="loading">Loading photos…</p>
    <p v-else-if="error" class="album-photos__error">{{ error }}</p>

    <ul v-else class="photo-list">
      <li v-for="photo in photos" :key="photo.id" class="photo-list__item">
        <img v-if="thumbSrc(photo)" :src="thumbSrc(photo)!" :alt="photo.title ?? 'Photo'" class="photo-list__thumb" />
        <div v-else class="photo-list__thumb photo-list__thumb--empty">No preview</div>

        <div class="photo-list__meta">
          <span>{{ photo.title ?? '(untitled)' }}</span>
          <span class="status-badge" :class="`status-badge--${photo.processingStatus}`">
            {{ STATUS_LABEL[photo.processingStatus] }}
          </span>
          <span v-if="photo.processingStatus === 'failed' && photo.processingError" class="photo-list__error">
            {{ photo.processingError }}
          </span>
        </div>

        <div class="photo-list__actions">
          <RouterLink :to="{ name: 'admin-photo-edit', params: { id: photo.id } }">Edit</RouterLink>
          <button type="button" :disabled="reprocessing.has(photo.id)" @click="reprocess(photo)">
            {{ reprocessing.has(photo.id) ? 'Reprocessing…' : 'Reprocess' }}
          </button>
        </div>
      </li>
      <li v-if="photos.length === 0" class="photo-list__empty">No photos uploaded yet.</li>
    </ul>
  </section>
</template>

<style scoped>
.album-photos__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.album-photos__error {
  color: #f87171;
}

.upload-box {
  margin: 1.5rem 0;
  padding: 1rem;
  border: 1px dashed #333;
  border-radius: 10px;
}

.upload-box__label {
  display: inline-flex;
  gap: 0.5rem;
  cursor: pointer;
}

.upload-box__status {
  margin: 0.5rem 0 0;
  color: var(--muted, #888);
  font-size: 0.85rem;
}

.photo-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.photo-list__item {
  display: grid;
  grid-template-columns: 64px 1fr auto;
  align-items: center;
  gap: 1rem;
  padding: 0.6rem;
  border: 1px solid #262626;
  border-radius: 8px;
}

.photo-list__thumb {
  width: 64px;
  height: 64px;
  object-fit: cover;
  border-radius: 6px;
  background: #1a1a1a;
}

.photo-list__thumb--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  color: var(--muted, #888);
  text-align: center;
}

.photo-list__meta {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.photo-list__error {
  color: #f87171;
  font-size: 0.75rem;
}

.photo-list__actions {
  display: flex;
  gap: 0.5rem;
}

.photo-list__empty {
  color: var(--muted, #888);
}

.status-badge {
  display: inline-block;
  width: fit-content;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
  background: #1a1a1a;
}

.status-badge--pending {
  color: #a1a1aa;
}

.status-badge--converting,
.status-badge--detecting {
  color: #facc15;
}

.status-badge--done {
  color: #4ade80;
}

.status-badge--failed {
  color: #f87171;
}

button {
  padding: 0.4rem 0.8rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #1a1a1a;
  color: inherit;
  cursor: pointer;
  font-size: 0.85rem;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
