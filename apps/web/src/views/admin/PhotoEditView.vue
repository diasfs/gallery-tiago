<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
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
import type { AdminPerson, AdminPhotoDetail, PersonSummary, Tag } from '../../api/types'
import { useAdminPersonSearch } from '../../composables/useAdminPersonSearch'

const props = defineProps<{ id: string }>()

const photo = ref<AdminPhotoDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)
const saved = ref(false)

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

const fullSrc = computed(() => (photo.value ? photoDisplayUrl(photo.value) : null))

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

// --- People --------------------------------------------------------------

const {
  query: peopleQuery,
  results: peopleResults,
  loading: peopleSearchLoading,
  search: searchPeople,
  clear: clearPeopleSearch,
} = useAdminPersonSearch()
const peopleBusy = ref(false)

function onPeopleSearchInput(event: Event) {
  peopleQuery.value = (event.target as HTMLInputElement).value
  void searchPeople()
}

async function addPerson(person: AdminPerson) {
  if (!photo.value) {
    return
  }
  peopleBusy.value = true
  try {
    await adminApi.addPersonToPhoto(photo.value.id, { personId: person.id })
    if (!photo.value.people.some((p) => p.id === person.id)) {
      photo.value.people.push({
        id: person.id,
        name: person.name,
        avatarCropPath: person.avatarCropPath ?? null,
      })
    }
    clearPeopleSearch()
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao adicionar pessoa: ${err.message}` : 'Falha ao adicionar pessoa.'
  } finally {
    peopleBusy.value = false
  }
}

async function createAndAddPerson() {
  const name = peopleQuery.value.trim()
  if (name === '' || !photo.value) {
    return
  }

  peopleBusy.value = true
  error.value = null
  try {
    const face = await adminApi.addPersonToPhoto(photo.value.id, { name })
    const personId = face.personId
    if (!personId) {
      throw new Error('missing personId')
    }
    if (!photo.value.people.some((p) => p.id === personId)) {
      photo.value.people.push({
        id: personId,
        name,
        avatarCropPath: null,
      })
    }
    clearPeopleSearch()
  } catch (err) {
    error.value =
      err instanceof ApiError
        ? `Falha ao criar/adicionar pessoa: ${err.message}`
        : 'Falha ao criar/adicionar pessoa.'
  } finally {
    peopleBusy.value = false
  }
}

async function removePerson(personId: string) {
  if (!photo.value) {
    return
  }
  peopleBusy.value = true
  try {
    await adminApi.removePersonFromPhoto(photo.value.id, personId)
    photo.value.people = photo.value.people.filter((p) => p.id !== personId)
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao remover pessoa: ${err.message}` : 'Falha ao remover pessoa.'
  } finally {
    peopleBusy.value = false
  }
}

function selectPersonResult(value: unknown) {
  const person = peopleResults.value.find((result) => result.id === value)
  if (person) {
    void addPerson(person)
  }
}

function personAvatarSrc(person: PersonSummary): string | null {
  return mediaUrl(person.avatarCropPath)
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
            <img
              v-if="fullSrc"
              :src="fullSrc"
              :alt="photo.title ?? 'Foto'"
              class="block aspect-[4/3] w-full bg-muted object-contain"
            />
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
          <CardDescription>Gerencie as pessoas nomeadas marcadas nesta foto.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <ul v-if="photo.people.length > 0" class="admin-people-list">
            <li v-for="person in photo.people" :key="person.id" data-testid="photo-person-row">
              <div class="flex min-w-0 items-center gap-3">
                <img
                  v-if="personAvatarSrc(person)"
                  :src="personAvatarSrc(person)!"
                  alt=""
                  class="size-10 shrink-0 rounded-md object-cover bg-muted"
                  data-testid="photo-person-avatar"
                />
                <div
                  v-else
                  class="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                  data-testid="photo-person-avatar-empty"
                >
                  —
                </div>
                <RouterLink
                  :to="{ name: 'admin-person-edit', params: { id: person.id } }"
                  class="truncate font-medium text-foreground hover:underline"
                >
                  {{ person.name ?? 'Sem nome' }}
                </RouterLink>
              </div>
              <button
                type="button"
                class="admin-action-link admin-action-link--danger"
                :disabled="peopleBusy"
                @click="removePerson(person.id)"
              >
                Remover
              </button>
            </li>
          </ul>
          <p v-else class="text-sm text-muted-foreground">Nenhuma pessoa marcada.</p>

          <div
            class="grid gap-2"
            :class="photo.people.length > 0 ? 'border-t border-border pt-4' : undefined"
          >
            <Label for="people-search" class="admin-label-sentence">Adicionar pessoa</Label>
            <div class="flex flex-col gap-2 sm:flex-row">
              <Input
                id="people-search"
                v-model="peopleQuery"
                placeholder="Buscar ou criar pelo nome…"
                class="flex-1"
                data-testid="people-search"
                :disabled="peopleBusy || peopleSearchLoading"
                @input="onPeopleSearchInput"
              />
              <Button
                type="button"
                variant="secondary"
                :disabled="peopleBusy || peopleQuery.trim() === ''"
                data-testid="people-create-add"
                @click="createAndAddPerson"
              >
                Adicionar / criar
              </Button>
            </div>
          </div>
          <Select
            v-if="peopleResults.length > 0"
            :disabled="peopleBusy"
            @update:model-value="selectPersonResult"
          >
            <SelectTrigger class="w-full" data-testid="people-results">
              <SelectValue placeholder="Escolha uma pessoa correspondente" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="person in peopleResults" :key="person.id" :value="person.id">
                {{ person.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </CardContent>
      </Card>
    </template>
  </section>
</template>
