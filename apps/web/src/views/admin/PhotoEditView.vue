<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { RefreshCw, RotateCw } from '@lucide/vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ApiError, adminApi, mediaUrl, photoDisplayUrl } from '../../api/client'
import type {
  AdminExcludedPhotoFace,
  AdminPhotoDetail,
  AdminPhotoFace,
  FaceExclusionReason,
  ReprocessScope,
  Tag,
} from '../../api/types'
import FaceReassignDialog from '../../components/admin/FaceReassignDialog.vue'
import PhotoMoveDialog from '../../components/admin/PhotoMoveDialog.vue'
import PhotoPeopleEditor from '../../components/admin/PhotoPeopleEditor.vue'

const props = defineProps<{ id: string }>()

const photo = ref<AdminPhotoDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)
const saved = ref(false)
const reprocessing = ref(false)
const rotating = ref(false)
const restoringFaceId = ref<string | null>(null)
const reassignFace = ref<AdminPhotoFace | null>(null)
const moveOpen = ref(false)
const previewVersion = ref(0)
const reprocessScope = ref<ReprocessScope>('all')

const SCOPE_LABEL: Record<ReprocessScope, string> = {
  all: 'Rostos + tags',
  faces: 'Apenas rostos',
  tags: 'Apenas tags',
}

const EXCLUSION_LABEL: Record<FaceExclusionReason, string> = {
  removed: 'Removido desta foto',
  person_trashed: 'Pessoa na lixeira',
}

const form = reactive<{ title: string }>({ title: '' })
const selectedTags = ref<Tag[]>([])

async function load() {
  loading.value = true
  error.value = null
  try {
    photo.value = await adminApi.getPhoto(props.id)
    form.title = photo.value.title ?? ''
    selectedTags.value = [...photo.value.tags]
  } catch {
    error.value = 'Falha ao carregar esta foto.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.id, load)

const fullSrc = computed(() => {
  if (!photo.value) return null
  const src = photoDisplayUrl(photo.value)
  if (!src) return null
  return previewVersion.value > 0 ? `${src}${src.includes('?') ? '&' : '?'}v=${previewVersion.value}` : src
})

const faceOverlays = computed(() => {
  const detail = photo.value
  if (!detail?.width || !detail.height || detail.width <= 0 || detail.height <= 0) {
    return []
  }
  return (detail.faces ?? []).filter(
    (face) => face.personId !== null && face.width > 0 && face.height > 0,
  )
})

const excludedFaceOverlays = computed(() => {
  const detail = photo.value
  if (!detail?.width || !detail.height || detail.width <= 0 || detail.height <= 0) {
    return []
  }
  return (detail.excludedFaces ?? []).filter((face) => face.width > 0 && face.height > 0)
})

function faceOverlayStyle(face: AdminPhotoFace): Record<string, string> {
  const detail = photo.value
  if (!detail?.width || !detail.height) {
    return { display: 'none' }
  }
  return {
    left: `${(face.x / detail.width) * 100}%`,
    top: `${(face.y / detail.height) * 100}%`,
    width: `${(face.width / detail.width) * 100}%`,
    height: `${(face.height / detail.height) * 100}%`,
  }
}

function faceOverlayLabel(face: AdminPhotoFace): string {
  return face.name?.trim() || 'Sem nome'
}

function exclusionReasonLabel(face: AdminExcludedPhotoFace): string {
  return EXCLUSION_LABEL[face.exclusionReason]
}

function excludedFaceAvatarSrc(face: AdminExcludedPhotoFace): string | null {
  return mediaUrl(face.avatarCropPath)
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    photo.value = await adminApi.updatePhoto(props.id, {
      title: form.title.trim() === '' ? null : form.title.trim(),
      tagIds: selectedTags.value.map((t) => t.id),
    })
    saved.value = true
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao salvar: ${err.message}` : 'Falha ao salvar.'
  } finally {
    saving.value = false
  }
}

// --- Tags --------------------------------------------------------------

const tagQuery = ref('')
const tagResults = ref<Tag[]>([])

async function searchTags() {
  const response = await adminApi.searchTags({
    q: tagQuery.value || undefined,
    page: 1,
    perPage: 20,
    sort: 'name',
  })
  tagResults.value = response.data
}

function addTag(tag: Tag) {
  if (!selectedTags.value.some((t) => t.id === tag.id)) {
    selectedTags.value.push(tag)
  }
  tagQuery.value = ''
  tagResults.value = []
}

async function createAndAddTag() {
  const name = tagQuery.value.trim()
  if (name === '') {
    return
  }
  try {
    const tag = await adminApi.createTag(name)
    addTag(tag)
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao criar tag: ${err.message}` : 'Falha ao criar tag.'
  }
}

function removeTag(tag: Tag) {
  selectedTags.value = selectedTags.value.filter((t) => t.id !== tag.id)
}

function selectTagResult(value: unknown) {
  const tag = tagResults.value.find((result) => result.id === value)
  if (tag) {
    addTag(tag)
  }
}

function onPeopleError(message: string) {
  error.value = message
}

async function onPeopleChanged() {
  // Reload so faces / excludedFaces stay in sync after add/remove.
  if (!photo.value) return
  try {
    photo.value = await adminApi.getPhoto(photo.value.id)
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao atualizar foto: ${err.message}` : 'Falha ao atualizar foto.'
  }
}

function openReassign(face: AdminPhotoFace) {
  reassignFace.value = face
}

function onReassignOpen(open: boolean) {
  if (!open) {
    reassignFace.value = null
  }
}

async function restoreExcludedFace(face: AdminExcludedPhotoFace) {
  if (!photo.value || restoringFaceId.value) return
  restoringFaceId.value = face.id
  error.value = null
  try {
    if (face.exclusionReason === 'person_trashed') {
      if (!face.personId) {
        throw new Error('missing personId')
      }
      await adminApi.restorePerson(face.personId)
      await load()
    } else {
      photo.value = await adminApi.restorePhotoFace(photo.value.id, face.id)
    }
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao restaurar rosto: ${err.message}` : 'Falha ao restaurar rosto.'
  } finally {
    restoringFaceId.value = null
  }
}

async function reprocess() {
  if (!photo.value || reprocessing.value) return
  reprocessing.value = true
  error.value = null
  saved.value = false
  try {
    photo.value = await adminApi.reprocessPhoto(photo.value.id, reprocessScope.value)
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao reprocessar: ${err.message}` : 'Falha ao reprocessar.'
  } finally {
    reprocessing.value = false
  }
}

async function onPhotoMoved() {
  if (!photo.value) return
  error.value = null
  try {
    photo.value = await adminApi.getPhoto(photo.value.id)
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao atualizar foto: ${err.message}` : 'Falha ao atualizar foto.'
  }
}

async function rotate() {
  if (!photo.value || rotating.value || reprocessing.value || saving.value) return
  rotating.value = true
  error.value = null
  saved.value = false
  try {
    photo.value = await adminApi.rotatePhoto(photo.value.id, 90)
    previewVersion.value += 1
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao rotacionar: ${err.message}` : 'Falha ao rotacionar.'
  } finally {
    rotating.value = false
  }
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink
        v-if="photo"
        :to="{ name: 'admin-album-photos', params: { albumId: photo.albumId } }"
        class="admin-back-link"
      >
        ← Fotos do álbum
      </RouterLink>
      <div v-if="photo" class="flex flex-wrap items-center gap-2">
        <Badge data-testid="status-media" variant="secondary" class="admin-status-badge">
          Mídia: {{ photo.mediaStatus }}
        </Badge>
        <Badge data-testid="status-faces" variant="secondary" class="admin-status-badge">
          Rostos: {{ photo.facesStatus }}
        </Badge>
        <Badge data-testid="status-tags" variant="secondary" class="admin-status-badge">
          Tags: {{ photo.tagsStatus }}
        </Badge>
        <Select
          :model-value="reprocessScope"
          :disabled="reprocessing || saving"
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
          :disabled="reprocessing || saving"
          data-testid="reprocess-photo"
          @click="reprocess"
        >
          <RefreshCw class="size-3.5 shrink-0" :class="{ 'animate-spin': reprocessing }" />
          {{ reprocessing ? 'Reprocessando…' : 'Reprocessar' }}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          :disabled="rotating || reprocessing || saving || !fullSrc"
          data-testid="rotate-photo"
          @click="rotate"
        >
          <RotateCw class="size-3.5 shrink-0" :class="{ 'animate-spin': rotating }" />
          {{ rotating ? 'Rotacionando…' : 'Rotacionar 90°' }}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          data-testid="photo-move"
          @click="moveOpen = true"
        >
          Mover para outro álbum
        </Button>
      </div>
    </div>

    <Alert v-if="photo?.processingError" variant="destructive" class="mt-0">
      <AlertDescription class="whitespace-pre-wrap">{{ photo.processingError }}</AlertDescription>
    </Alert>

    <div v-if="loading" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
      Carregando foto…
    </div>

    <Alert v-else-if="!photo" variant="destructive">
      <AlertDescription>Foto não encontrada.</AlertDescription>
    </Alert>

    <template v-else>
      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>
      <Alert v-if="saved">
        <AlertDescription>Salvo.</AlertDescription>
      </Alert>

      <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(22rem,0.85fr)] lg:items-start">
        <Card class="overflow-hidden">
          <CardContent class="p-0">
            <div v-if="fullSrc" class="relative w-full bg-muted">
              <img
                :src="fullSrc"
                :alt="photo.title ?? 'Foto'"
                class="block h-auto w-full"
                data-testid="admin-photo-preview"
              />
              <button
                v-for="face in faceOverlays"
                :key="face.id"
                type="button"
                class="photo-face-overlay"
                :style="faceOverlayStyle(face)"
                :title="faceOverlayLabel(face)"
                :aria-label="`Vincular ${faceOverlayLabel(face)}`"
                data-testid="photo-face-overlay"
                @click="openReassign(face)"
              >
                <span class="photo-face-overlay__label">{{ faceOverlayLabel(face) }}</span>
              </button>
              <div
                v-for="face in excludedFaceOverlays"
                :key="`excluded-${face.id}`"
                class="photo-face-overlay photo-face-overlay--excluded"
                :style="faceOverlayStyle(face)"
                :title="`${faceOverlayLabel(face)} · ${exclusionReasonLabel(face)}`"
                data-testid="photo-face-overlay-excluded"
              >
                <span class="photo-face-overlay__label">
                  {{ faceOverlayLabel(face) }} · {{ exclusionReasonLabel(face) }}
                </span>
              </div>
            </div>
            <div v-else class="flex aspect-[4/3] items-center justify-center text-sm text-muted-foreground">
              Sem prévia disponível
            </div>
          </CardContent>
        </Card>

        <form @submit.prevent="save">
          <Card>
            <CardHeader>
              <CardTitle>Editar foto</CardTitle>
              <CardDescription>Atualize o título e as tags desta foto.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
              <div class="grid gap-2">
                <Label for="photo-title">Título</Label>
                <Input id="photo-title" v-model="form.title" />
              </div>

              <fieldset class="space-y-3 rounded-lg border p-4">
                <Label as="legend">Tags</Label>
                <div class="flex flex-wrap gap-2">
                  <Badge v-for="tag in selectedTags" :key="tag.id" variant="secondary" class="gap-1 pl-2">
                    #{{ tag.name }}
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="size-5 rounded-full"
                      :aria-label="`Remover tag ${tag.name}`"
                      @click="removeTag(tag)"
                    >
                      ×
                    </Button>
                  </Badge>
                  <span v-if="selectedTags.length === 0" class="text-sm text-muted-foreground">Nenhuma tag ainda.</span>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                  <Input
                    v-model="tagQuery"
                    placeholder="Buscar ou criar uma tag…"
                    class="flex-1"
                    @input="searchTags"
                  />
                  <Button type="button" variant="secondary" @click="createAndAddTag">Adicionar / criar</Button>
                </div>
                <Select v-if="tagResults.length > 0" @update:model-value="selectTagResult">
                  <SelectTrigger class="w-full">
                    <SelectValue placeholder="Escolha uma tag correspondente" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="tag in tagResults" :key="tag.id" :value="tag.id">
                      #{{ tag.name }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </fieldset>

              <Button type="submit" size="sm" class="mt-2 px-5" :disabled="saving">
                {{ saving ? 'Salvando…' : 'Salvar' }}
              </Button>
            </CardContent>
          </Card>
        </form>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Pessoas</CardTitle>
          <CardDescription>
            Vincule cada rosto à pessoa certa. Remover tira só aquele rosto desta foto.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <PhotoPeopleEditor
            :photo-id="photo.id"
            :faces="photo.faces ?? []"
            v-model:people="photo.people"
            @error="onPeopleError"
            @changed="onPeopleChanged"
            @reassign="openReassign"
          />
        </CardContent>
      </Card>

      <FaceReassignDialog
        :open="reassignFace !== null"
        :photo-id="photo.id"
        :face="reassignFace"
        @update:open="onReassignOpen"
        @changed="onPeopleChanged"
        @error="onPeopleError"
      />
      <PhotoMoveDialog
        :open="moveOpen"
        :photo-ids="[photo.id]"
        :exclude-album-id="photo.albumId"
        @update:open="(open) => (moveOpen = open)"
        @moved="onPhotoMoved"
      />

      <Card data-testid="excluded-faces-card">
        <CardHeader>
          <CardTitle>Rostos excluídos</CardTitle>
          <CardDescription>
            Removidos desta foto ou vinculados a pessoas na lixeira. Restaure para voltar a marcá-los.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
          <ul
            v-if="(photo.excludedFaces ?? []).length > 0"
            class="admin-people-list"
            data-testid="excluded-faces-list"
          >
            <li
              v-for="face in photo.excludedFaces"
              :key="face.id"
              data-testid="excluded-face-row"
            >
              <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
                <img
                  v-if="excludedFaceAvatarSrc(face)"
                  :src="excludedFaceAvatarSrc(face)!"
                  alt=""
                  class="size-10 shrink-0 rounded-md object-cover bg-muted"
                  data-testid="excluded-face-avatar"
                />
                <div
                  v-else
                  class="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                  data-testid="excluded-face-avatar-empty"
                >
                  —
                </div>
                <RouterLink
                  v-if="face.personId"
                  :to="{ name: 'admin-person-edit', params: { id: face.personId } }"
                  class="truncate font-medium text-foreground hover:underline"
                >
                  {{ faceOverlayLabel(face) }}
                </RouterLink>
                <span v-else class="truncate font-medium">{{ faceOverlayLabel(face) }}</span>
                <Badge variant="outline" data-testid="excluded-face-reason">
                  {{ exclusionReasonLabel(face) }}
                </Badge>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="restoringFaceId === face.id"
                data-testid="excluded-face-restore"
                @click="restoreExcludedFace(face)"
              >
                {{ restoringFaceId === face.id ? 'Restaurando…' : 'Restaurar' }}
              </Button>
            </li>
          </ul>
          <p v-else class="text-sm text-muted-foreground" data-testid="excluded-faces-empty">
            Nenhum rosto excluído nesta foto.
          </p>
        </CardContent>
      </Card>
    </template>
  </section>
</template>

<style scoped>
.photo-face-overlay {
  position: absolute;
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  border: 2px solid rgba(250, 250, 250, 0.9);
  border-radius: 4px;
  background: transparent;
  pointer-events: auto;
  cursor: pointer;
  appearance: none;
}

.photo-face-overlay--excluded {
  border-style: dashed;
  border-color: rgba(161, 161, 170, 0.95);
  background: rgba(24, 24, 27, 0.18);
}

.photo-face-overlay__label {
  position: absolute;
  left: 0;
  bottom: calc(100% + 4px);
  max-width: min(12rem, 40vw);
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.75);
  color: #fff;
  font-size: 0.7rem;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.12s ease;
}

.photo-face-overlay:hover .photo-face-overlay__label,
.photo-face-overlay:focus-visible .photo-face-overlay__label {
  opacity: 1;
}
</style>
