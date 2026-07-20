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
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, AdminPhotoDetail, Tag } from '../../api/types'

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
    error.value = 'Failed to load this photo.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.id, load)

const fullSrc = computed(() => mediaUrl(photo.value?.avifPath))

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
    error.value = err instanceof ApiError ? `Save failed: ${err.message}` : 'Save failed.'
  } finally {
    saving.value = false
  }
}

// --- Tags --------------------------------------------------------------

const tagQuery = ref('')
const tagResults = ref<Tag[]>([])

async function searchTags() {
  tagResults.value = await adminApi.searchTags(tagQuery.value || undefined)
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
    error.value = err instanceof ApiError ? `Failed to create tag: ${err.message}` : 'Failed to create tag.'
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

const peopleQuery = ref('')
const peopleResults = ref<AdminPerson[]>([])
const peopleBusy = ref(false)

async function searchPeople() {
  peopleResults.value = await adminApi.searchPeople(peopleQuery.value || undefined)
}

async function addPerson(person: AdminPerson) {
  if (!photo.value) {
    return
  }
  peopleBusy.value = true
  try {
    await adminApi.addPersonToPhoto(photo.value.id, person.id)
    if (!photo.value.people.some((p) => p.id === person.id)) {
      photo.value.people.push({ id: person.id, name: person.name })
    }
    peopleQuery.value = ''
    peopleResults.value = []
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to add person: ${err.message}` : 'Failed to add person.'
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
    error.value = err instanceof ApiError ? `Failed to remove person: ${err.message}` : 'Failed to remove person.'
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
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink
        v-if="photo"
        :to="{ name: 'admin-album-photos', params: { albumId: photo.albumId } }"
        class="admin-back-link"
      >
        ← Album photos
      </RouterLink>
      <Badge v-if="photo" variant="secondary" class="admin-status-badge">{{ photo.processingStatus }}</Badge>
    </div>

    <div v-if="loading" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
      Loading photo…
    </div>

    <Alert v-else-if="!photo" variant="destructive">
      <AlertDescription>Photo not found.</AlertDescription>
    </Alert>

    <template v-else>
      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>
      <Alert v-if="saved">
        <AlertDescription>Saved.</AlertDescription>
      </Alert>

      <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(22rem,0.85fr)] lg:items-start">
        <Card class="overflow-hidden">
          <CardContent class="p-0">
            <img
              v-if="fullSrc"
              :src="fullSrc"
              :alt="photo.title ?? 'Photo'"
              class="block aspect-[4/3] w-full bg-muted object-contain"
            />
            <div v-else class="flex aspect-[4/3] items-center justify-center text-sm text-muted-foreground">
              No preview available
            </div>
          </CardContent>
        </Card>

        <form @submit.prevent="save">
          <Card>
            <CardHeader>
              <CardTitle>Edit photo</CardTitle>
              <CardDescription>Update the title and tags for this photo.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
              <div class="grid gap-2">
                <Label for="photo-title">Title</Label>
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
                      :aria-label="`Remove ${tag.name} tag`"
                      @click="removeTag(tag)"
                    >
                      ×
                    </Button>
                  </Badge>
                  <span v-if="selectedTags.length === 0" class="text-sm text-muted-foreground">No tags yet.</span>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                  <Input
                    v-model="tagQuery"
                    placeholder="Search or create a tag…"
                    class="flex-1"
                    @input="searchTags"
                  />
                  <Button type="button" variant="secondary" @click="createAndAddTag">Add / create</Button>
                </div>
                <Select v-if="tagResults.length > 0" @update:model-value="selectTagResult">
                  <SelectTrigger class="w-full">
                    <SelectValue placeholder="Choose a matching tag" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="tag in tagResults" :key="tag.id" :value="tag.id">
                      #{{ tag.name }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </fieldset>

              <Button type="submit" size="sm" class="mt-2 px-5" :disabled="saving">
                {{ saving ? 'Saving…' : 'Save changes' }}
              </Button>
            </CardContent>
          </Card>
        </form>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>People</CardTitle>
          <CardDescription>Manage the named people tagged in this photo.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <ul v-if="photo.people.length > 0" class="admin-people-list">
            <li v-for="person in photo.people" :key="person.id">
              <span class="font-medium">{{ person.name ?? 'Unnamed' }}</span>
              <button
                type="button"
                class="admin-action-link admin-action-link--danger"
                :disabled="peopleBusy"
                @click="removePerson(person.id)"
              >
                Remove
              </button>
            </li>
          </ul>
          <p v-else class="text-sm text-muted-foreground">No people tagged.</p>

          <div
            class="grid gap-2"
            :class="photo.people.length > 0 ? 'border-t border-border pt-4' : undefined"
          >
            <Label for="people-search" class="admin-label-sentence">Add a person</Label>
            <Input
              id="people-search"
              v-model="peopleQuery"
              placeholder="Search named people…"
              @input="searchPeople"
            />
          </div>
          <Select
            v-if="peopleResults.length > 0"
            :disabled="peopleBusy"
            @update:model-value="selectPersonResult"
          >
            <SelectTrigger class="w-full">
              <SelectValue placeholder="Choose a matching person" />
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
