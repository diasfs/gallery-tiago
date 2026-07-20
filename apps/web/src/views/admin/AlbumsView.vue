<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { ApiError, adminApi, type AlbumWritePayload } from '../../api/client'
import type { AdminAlbum, Location } from '../../api/types'
import LocationMap from '../../components/LocationMap.vue'
import { toDateInputValue } from '@/lib/utils'

const albums = ref<AdminAlbum[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

interface FlatAlbum {
  album: AdminAlbum
  depth: number
}

/** Depth-first flattening (root-first, ordered by sortOrder/title) so the tree renders as an indented list without a recursive component. */
const flatAlbums = computed<FlatAlbum[]>(() => {
  const byParent = new Map<string | null, AdminAlbum[]>()
  for (const album of albums.value) {
    const key = album.parentId
    const siblings = byParent.get(key) ?? []
    siblings.push(album)
    byParent.set(key, siblings)
  }
  for (const siblings of byParent.values()) {
    siblings.sort((a, b) => a.sortOrder - b.sortOrder || a.title.localeCompare(b.title))
  }

  const result: FlatAlbum[] = []
  function walk(parentId: string | null, depth: number) {
    for (const album of byParent.get(parentId) ?? []) {
      result.push({ album, depth })
      walk(album.id, depth + 1)
    }
  }
  walk(null, 0)
  return result
})

async function load() {
  loading.value = true
  error.value = null
  try {
    albums.value = await adminApi.listAlbums()
  } catch {
    error.value = 'Failed to load albums.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const editingId = ref<string | 'new' | null>(null)
const deletingAlbum = ref<AdminAlbum | null>(null)
const formError = ref<string | null>(null)
const form = reactive<{
  title: string
  slug: string
  description: string
  visibility: 'public' | 'unlisted' | 'private'
  sortOrder: number
  parentId: string
  coverPhotoId: string
  takenAt: string
}>({
  title: '',
  slug: '',
  description: '',
  visibility: 'private',
  sortOrder: 0,
  parentId: '',
  coverPhotoId: '',
  takenAt: '',
})

const selectedLocation = ref<Location | null>(null)
const locationQuery = ref('')
const locationResults = ref<Location[]>([])
const showNewLocationForm = ref(false)
const newLocation = reactive({ name: '', city: '', country: '', latitude: '', longitude: '' })
const hasCoordinates = computed(
  () => selectedLocation.value?.latitude != null && selectedLocation.value?.longitude != null,
)

function resetForm() {
  form.title = ''
  form.slug = ''
  form.description = ''
  form.visibility = 'private'
  form.sortOrder = 0
  form.parentId = ''
  form.coverPhotoId = ''
  form.takenAt = ''
  selectedLocation.value = null
  locationQuery.value = ''
  locationResults.value = []
  showNewLocationForm.value = false
  formError.value = null
}

function startCreate(parentId?: string) {
  resetForm()
  form.parentId = parentId ?? ''
  editingId.value = 'new'
}

function startEdit(album: AdminAlbum) {
  resetForm()
  form.title = album.title
  form.slug = album.slug
  form.description = album.description ?? ''
  form.visibility = album.visibility
  form.sortOrder = album.sortOrder
  form.parentId = album.parentId ?? ''
  form.coverPhotoId = album.coverPhotoId ?? ''
  form.takenAt = album.takenAt ? toDateInputValue(album.takenAt) : ''
  selectedLocation.value = album.location
  editingId.value = album.id
}

function cancelEdit() {
  editingId.value = null
}

function handleEditDialogOpen(open: boolean) {
  if (!open) cancelEdit()
}

function updateVisibility(value: unknown) {
  if (value === 'public' || value === 'unlisted' || value === 'private') {
    form.visibility = value
  }
}

function updateParentId(value: unknown) {
  form.parentId = value === '__root__' ? '' : String(value ?? '')
}

async function searchLocations() {
  locationResults.value = await adminApi.searchLocations(locationQuery.value || undefined)
}

function chooseLocation(location: Location) {
  selectedLocation.value = location
  locationQuery.value = ''
  locationResults.value = []
}

function clearLocation() {
  selectedLocation.value = null
}

function selectLocationResult(value: unknown) {
  const location = locationResults.value.find((result) => result.id === value)
  if (location) {
    chooseLocation(location)
  }
}

async function createLocation() {
  if (newLocation.name.trim() === '') {
    return
  }
  try {
    const location = await adminApi.createLocation({
      name: newLocation.name.trim(),
      city: newLocation.city.trim() === '' ? null : newLocation.city.trim(),
      country: newLocation.country.trim() === '' ? null : newLocation.country.trim(),
      latitude: newLocation.latitude === '' ? null : Number(newLocation.latitude),
      longitude: newLocation.longitude === '' ? null : Number(newLocation.longitude),
    })
    chooseLocation(location)
    showNewLocationForm.value = false
    newLocation.name = ''
    newLocation.city = ''
    newLocation.country = ''
    newLocation.latitude = ''
    newLocation.longitude = ''
  } catch (err) {
    formError.value = err instanceof ApiError ? `Failed to create location: ${err.message}` : 'Failed to create location.'
  }
}

function badgeVariant(visibility: AdminAlbum['visibility']) {
  if (visibility === 'public') return 'default'
  if (visibility === 'unlisted') return 'secondary'
  return 'outline'
}

async function submit() {
  formError.value = null
  if (!form.title.trim() || !form.slug.trim()) {
    formError.value = 'Title and slug are required.'
    return
  }

  const payload: AlbumWritePayload = {
    title: form.title.trim(),
    slug: form.slug.trim(),
    description: form.description.trim() === '' ? null : form.description.trim(),
    visibility: form.visibility,
    sortOrder: form.sortOrder,
    parentId: form.parentId === '' ? null : form.parentId,
    coverPhotoId: form.coverPhotoId.trim() === '' ? null : form.coverPhotoId.trim(),
    takenAt: form.takenAt === '' ? null : new Date(`${form.takenAt}T00:00:00.000Z`).toISOString(),
    locationId: selectedLocation.value?.id ?? null,
  }

  try {
    if (editingId.value === 'new') {
      await adminApi.createAlbum(payload)
    } else if (editingId.value) {
      await adminApi.updateAlbum(editingId.value, payload)
    }
    editingId.value = null
    await load()
  } catch (err) {
    formError.value = err instanceof ApiError ? `Save failed: ${err.message}` : 'Save failed.'
  }
}

function remove(album: AdminAlbum) {
  deletingAlbum.value = album
}

async function confirmDelete() {
  if (!deletingAlbum.value) return

  try {
    await adminApi.deleteAlbum(deletingAlbum.value.id)
    deletingAlbum.value = null
    await load()
  } catch {
    error.value = 'Delete failed.'
  }
}
</script>

<template>
  <section class="space-y-5">
    <div class="admin-toolbar">
      <p class="text-sm text-muted-foreground">
        {{ flatAlbums.length === 1 ? '1 album' : `${flatAlbums.length} albums` }}
      </p>
      <Button type="button" size="sm" class="h-9 px-4" @click="startCreate()">New album</Button>
    </div>

    <div v-if="loading" class="admin-panel rounded-2xl p-16 text-center text-sm text-muted-foreground">
      Loading albums…
    </div>

    <Alert v-else-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-else class="admin-panel overflow-hidden rounded-2xl">
      <Table>
        <TableHeader>
          <TableRow class="hover:bg-transparent">
            <TableHead class="admin-table-head">Title</TableHead>
            <TableHead class="admin-table-head">Visibility</TableHead>
            <TableHead class="admin-table-head w-20 text-right">Order</TableHead>
            <TableHead class="admin-table-head w-20 text-right">Photos</TableHead>
            <TableHead class="admin-table-head text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="{ album, depth } in flatAlbums" :key="album.id" class="border-border/60 hover:bg-muted/50">
            <TableCell class="py-4 font-medium text-foreground">
              <span class="inline-flex items-center" :style="{ paddingLeft: `${depth * 1.25}rem` }">
                <span v-if="depth > 0" class="mr-2 text-muted-foreground/70" aria-hidden="true">↳</span>
                {{ album.title }}
              </span>
            </TableCell>
            <TableCell class="py-4">
              <Badge :variant="badgeVariant(album.visibility)" class="rounded-full px-2.5 py-0.5 text-[11px] font-medium capitalize">
                {{ album.visibility }}
              </Badge>
            </TableCell>
            <TableCell class="py-4 text-right tabular-nums text-muted-foreground">{{ album.sortOrder }}</TableCell>
            <TableCell class="py-4 text-right tabular-nums text-muted-foreground">{{ album.photoCount }}</TableCell>
            <TableCell class="py-4">
              <div class="admin-row-actions">
                <RouterLink
                  :to="{ name: 'admin-album-photos', params: { albumId: album.id } }"
                  class="admin-action-link admin-action-link--primary"
                >
                  Photos
                </RouterLink>
                <span class="admin-action-sep" aria-hidden="true">·</span>
                <button type="button" class="admin-action-link" @click="startCreate(album.id)">Sub-album</button>
                <span class="admin-action-sep" aria-hidden="true">·</span>
                <button type="button" class="admin-action-link" @click="startEdit(album)">Edit</button>
                <span class="admin-action-sep" aria-hidden="true">·</span>
                <button type="button" class="admin-action-link admin-action-link--danger" @click="remove(album)">
                  Delete
                </button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-if="flatAlbums.length === 0">
            <TableCell colspan="5" class="h-32 text-center">
              <p class="text-muted-foreground">No albums yet.</p>
              <Button type="button" variant="link" class="mt-2" @click="startCreate()">Create your first album</Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <Dialog :open="editingId !== null" @update:open="handleEditDialogOpen">
      <DialogContent class="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{{ editingId === 'new' ? 'New album' : 'Edit album' }}</DialogTitle>
          <DialogDescription>
            {{ editingId === 'new' ? 'Create a collection for your photos.' : 'Update this album’s details and placement.' }}
          </DialogDescription>
        </DialogHeader>

        <form class="grid gap-4" @submit.prevent="submit">
          <div class="grid gap-2">
            <Label for="album-title">Title</Label>
            <Input id="album-title" v-model="form.title" required />
          </div>

          <div class="grid gap-2">
            <Label for="album-slug">Slug</Label>
            <Input id="album-slug" v-model="form.slug" required />
          </div>

          <div class="grid gap-2">
            <Label for="album-description">Description</Label>
            <Textarea id="album-description" v-model="form.description" rows="3" />
          </div>

          <div class="grid gap-2">
            <Label for="album-taken-at">Date</Label>
            <Input id="album-taken-at" v-model="form.takenAt" type="date" />
          </div>

          <fieldset class="space-y-3 rounded-lg border p-4">
            <Label as="legend">Location</Label>
            <div v-if="selectedLocation" class="flex flex-wrap items-center justify-between gap-2 text-sm">
              <span>
                {{ [selectedLocation.name, selectedLocation.city, selectedLocation.country].filter(Boolean).join(', ') }}
              </span>
              <Button type="button" variant="ghost" size="sm" @click="clearLocation">Clear</Button>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
              <Input
                v-model="locationQuery"
                placeholder="Search locations…"
                class="flex-1"
                @input="searchLocations"
              />
              <Button type="button" variant="secondary" @click="showNewLocationForm = !showNewLocationForm">
                New location
              </Button>
            </div>
            <Select v-if="locationResults.length > 0" @update:model-value="selectLocationResult">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Choose a matching location" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="location in locationResults" :key="location.id" :value="location.id">
                  {{ [location.name, location.city].filter(Boolean).join(', ') }}
                </SelectItem>
              </SelectContent>
            </Select>
            <div v-if="showNewLocationForm" class="grid gap-3 rounded-md bg-muted/40 p-3 sm:grid-cols-2">
              <div class="grid gap-2 sm:col-span-2">
                <Label for="album-location-name">Name</Label>
                <Input id="album-location-name" v-model="newLocation.name" />
              </div>
              <div class="grid gap-2">
                <Label for="album-location-city">City</Label>
                <Input id="album-location-city" v-model="newLocation.city" />
              </div>
              <div class="grid gap-2">
                <Label for="album-location-country">Country</Label>
                <Input id="album-location-country" v-model="newLocation.country" />
              </div>
              <div class="grid gap-2">
                <Label for="album-location-latitude">Latitude</Label>
                <Input id="album-location-latitude" v-model="newLocation.latitude" inputmode="decimal" />
              </div>
              <div class="grid gap-2">
                <Label for="album-location-longitude">Longitude</Label>
                <Input id="album-location-longitude" v-model="newLocation.longitude" inputmode="decimal" />
              </div>
              <Button type="button" variant="outline" size="sm" class="sm:col-span-2 sm:justify-self-start" @click="createLocation">
                Save location
              </Button>
            </div>
            <div v-if="hasCoordinates" class="overflow-hidden rounded-md border">
              <LocationMap
                :latitude="selectedLocation!.latitude!"
                :longitude="selectedLocation!.longitude!"
                :label="selectedLocation!.name"
              />
            </div>
          </fieldset>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
              <Label for="album-visibility">Visibility</Label>
              <Select :model-value="form.visibility" @update:model-value="updateVisibility">
                <SelectTrigger id="album-visibility" class="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="public">Public</SelectItem>
                  <SelectItem value="unlisted">Unlisted</SelectItem>
                  <SelectItem value="private">Private</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div class="grid gap-2">
              <Label for="album-sort-order">Sort order</Label>
              <Input id="album-sort-order" v-model.number="form.sortOrder" type="number" />
            </div>
          </div>

          <div class="grid gap-2">
            <Label for="album-parent">Parent album</Label>
            <Select :model-value="form.parentId || '__root__'" @update:model-value="updateParentId">
              <SelectTrigger id="album-parent" class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__root__">(root)</SelectItem>
                <SelectItem
                  v-for="album in albums"
                  :key="album.id"
                  :value="album.id"
                  :disabled="album.id === editingId"
                >
                  {{ album.title }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="grid gap-2">
            <Label for="album-cover-photo">Cover photo ID</Label>
            <Input id="album-cover-photo" v-model="form.coverPhotoId" placeholder="Optional photo UUID" />
          </div>

          <Alert v-if="formError" variant="destructive">
            <AlertDescription>{{ formError }}</AlertDescription>
          </Alert>

          <DialogFooter class="gap-2 sm:gap-2">
            <Button type="button" variant="outline" @click="cancelEdit">Cancel</Button>
            <Button type="submit">Save changes</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <Dialog :open="deletingAlbum !== null" @update:open="(open) => { if (!open) deletingAlbum = null }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Delete album?</DialogTitle>
          <DialogDescription>
            Delete “{{ deletingAlbum?.title }}” and all of its sub-albums and photos? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button type="button" variant="outline" @click="deletingAlbum = null">Cancel</Button>
          <Button type="button" variant="destructive" class="admin-btn-danger-solid" @click="confirmDelete">
            Delete album
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
