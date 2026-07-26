<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
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
import { ApiError, adminApi, mediaUrl, type AlbumWritePayload } from '../../api/client'
import type { AdminAlbum, AdminPhotoSummary, Location } from '../../api/types'
import LocationMap from '../../components/LocationMap.vue'
import { toDateInputValue } from '@/lib/utils'

type AlbumVisibilityFilter = 'all' | AdminAlbum['visibility']

const route = useRoute()
const router = useRouter()

const albums = ref<AdminAlbum[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const locationFilter = ref('')

const visibilityFilter = computed<AlbumVisibilityFilter>(() => {
  const value = route.query.visibility
  if (value === 'public' || value === 'unlisted' || value === 'private') return value
  return 'all'
})

const searchQuery = computed(() => {
  const q = route.query.q
  return typeof q === 'string' ? q.trim().toLowerCase() : ''
})

const fromQuery = computed(() => {
  const value = route.query.from
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : ''
})

const toQuery = computed(() => {
  const value = route.query.to
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : ''
})

const locationQueryFilter = computed(() => {
  const value = route.query.location
  return typeof value === 'string' ? value.trim().toLowerCase() : ''
})

const filtersActive = computed(
  () =>
    visibilityFilter.value !== 'all' ||
    searchQuery.value !== '' ||
    fromQuery.value !== '' ||
    toQuery.value !== '' ||
    locationQueryFilter.value !== '',
)

interface FlatAlbum {
  album: AdminAlbum
  depth: number
}

function albumMatchesLocation(album: AdminAlbum, needle: string): boolean {
  if (!album.location) return false
  const haystack = [album.location.name, album.location.city, album.location.country]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
  return haystack.includes(needle)
}

const filteredAlbums = computed(() => {
  const visibility = visibilityFilter.value
  const q = searchQuery.value
  const from = fromQuery.value
  const to = toQuery.value
  const locationNeedle = locationQueryFilter.value
  return albums.value.filter((album) => {
    if (visibility !== 'all' && album.visibility !== visibility) return false
    if (q && !album.title.toLowerCase().includes(q) && !album.slug.toLowerCase().includes(q)) {
      return false
    }
    if (from || to) {
      if (!album.takenAt) return false
      const taken = toDateInputValue(album.takenAt)
      if (from && taken < from) return false
      if (to && taken > to) return false
    }
    if (locationNeedle && !albumMatchesLocation(album, locationNeedle)) return false
    return true
  })
})

/** Depth-first flattening (root-first, ordered by sortOrder/title) so the tree renders as an indented list without a recursive component. */
const flatAlbums = computed<FlatAlbum[]>(() => {
  const filtered = filteredAlbums.value
  const ids = new Set(filtered.map((album) => album.id))
  const byParent = new Map<string | null, AdminAlbum[]>()

  for (const album of filtered) {
    // When filters hide a parent, promote the matching child to a root so it still appears.
    const key = album.parentId && ids.has(album.parentId) ? album.parentId : null
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

watch(
  () => route.query,
  (query) => {
    search.value = typeof query.q === 'string' ? query.q : ''
    dateFrom.value = typeof query.from === 'string' ? query.from : ''
    dateTo.value = typeof query.to === 'string' ? query.to : ''
    locationFilter.value = typeof query.location === 'string' ? query.location : ''
  },
  { immediate: true },
)

function albumFilterQuery(overrides: {
  visibility?: AlbumVisibilityFilter
  q?: string
  from?: string
  to?: string
  location?: string
} = {}) {
  const nextVisibility = overrides.visibility ?? visibilityFilter.value
  const nextSearch = overrides.q ?? search.value
  const nextFrom = overrides.from ?? dateFrom.value
  const nextTo = overrides.to ?? dateTo.value
  const nextLocation = overrides.location ?? locationFilter.value
  return {
    ...(nextVisibility !== 'all' ? { visibility: nextVisibility } : {}),
    ...(nextSearch.trim() ? { q: nextSearch.trim() } : {}),
    ...(nextFrom.trim() ? { from: nextFrom.trim() } : {}),
    ...(nextTo.trim() ? { to: nextTo.trim() } : {}),
    ...(nextLocation.trim() ? { location: nextLocation.trim() } : {}),
  }
}

function setVisibilityFilter(next: AlbumVisibilityFilter) {
  router.push({
    name: 'admin-albums',
    query: albumFilterQuery({ visibility: next }),
  })
}

function submitSearch() {
  router.push({
    name: 'admin-albums',
    query: albumFilterQuery({
      q: search.value,
      from: dateFrom.value,
      to: dateTo.value,
      location: locationFilter.value,
    }),
  })
}

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

const coverPhotos = ref<AdminPhotoSummary[]>([])
const coverPhotosLoading = ref(false)
const coverPhotosError = ref<string | null>(null)

const selectedCoverPhoto = computed(
  () => coverPhotos.value.find((photo) => photo.id === form.coverPhotoId) ?? null,
)

function coverThumbSrc(photo: AdminPhotoSummary): string | null {
  const thumb = Object.values(photo.thumbPaths ?? {})[0]
  return mediaUrl(thumb ?? photo.avifPath)
}

async function loadCoverPhotos(albumId: string) {
  coverPhotosLoading.value = true
  coverPhotosError.value = null
  try {
    coverPhotos.value = await adminApi.listAlbumPhotos(albumId)
  } catch {
    coverPhotos.value = []
    coverPhotosError.value = 'Failed to load album photos for cover selection.'
  } finally {
    coverPhotosLoading.value = false
  }
}

function chooseCoverPhoto(photoId: string) {
  form.coverPhotoId = photoId
}

function clearCoverPhoto() {
  form.coverPhotoId = ''
}

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
  coverPhotos.value = []
  coverPhotosLoading.value = false
  coverPhotosError.value = null
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
  void loadCoverPhotos(album.id)
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
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div class="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'all' ? 'default' : 'outline'"
          data-testid="visibility-all"
          @click="setVisibilityFilter('all')"
        >
          All
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'public' ? 'default' : 'outline'"
          data-testid="visibility-public"
          @click="setVisibilityFilter('public')"
        >
          Public
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'unlisted' ? 'default' : 'outline'"
          data-testid="visibility-unlisted"
          @click="setVisibilityFilter('unlisted')"
        >
          Unlisted
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'private' ? 'default' : 'outline'"
          data-testid="visibility-private"
          @click="setVisibilityFilter('private')"
        >
          Private
        </Button>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" class="h-9 px-4" @click="startCreate()">New album</Button>
      </div>
    </div>

    <form
      class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3 sm:flex-row sm:flex-wrap sm:items-end"
      data-testid="albums-filters"
      @submit.prevent="submitSearch"
    >
      <div class="grid gap-1.5">
        <Label for="albums-from" class="text-xs text-muted-foreground">From</Label>
        <Input
          id="albums-from"
          v-model="dateFrom"
          type="date"
          class="w-full sm:w-40"
          data-testid="albums-from"
        />
      </div>
      <div class="grid gap-1.5">
        <Label for="albums-to" class="text-xs text-muted-foreground">To</Label>
        <Input
          id="albums-to"
          v-model="dateTo"
          type="date"
          class="w-full sm:w-40"
          data-testid="albums-to"
        />
      </div>
      <div class="grid min-w-[12rem] flex-1 gap-1.5">
        <Label for="albums-location" class="text-xs text-muted-foreground">Location</Label>
        <Input
          id="albums-location"
          v-model="locationFilter"
          type="search"
          placeholder="Name, city, or country…"
          data-testid="albums-location"
        />
      </div>
      <div class="grid min-w-[12rem] flex-1 gap-1.5">
        <Label for="albums-search" class="text-xs text-muted-foreground">Title / slug</Label>
        <Input
          id="albums-search"
          v-model="search"
          type="search"
          placeholder="Search title or slug…"
          data-testid="albums-search"
        />
      </div>
      <Button type="submit" variant="outline" size="sm" class="h-9">Apply filters</Button>
    </form>

    <p class="text-sm text-muted-foreground">
      {{ flatAlbums.length === 1 ? '1 album' : `${flatAlbums.length} albums` }}
      <span v-if="filtersActive && albums.length !== flatAlbums.length">
        (of {{ albums.length }})
      </span>
    </p>

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
          <TableRow
            v-for="{ album, depth } in flatAlbums"
            :key="album.id"
            data-testid="album-row"
            class="border-border/60 hover:bg-muted/50"
          >
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
            <TableCell colspan="5" class="h-32 text-center" data-testid="albums-empty">
              <p class="text-muted-foreground">
                {{ filtersActive ? 'No albums match this filter.' : 'No albums yet.' }}
              </p>
              <Button
                v-if="!filtersActive"
                type="button"
                variant="link"
                class="mt-2"
                @click="startCreate()"
              >
                Create your first album
              </Button>
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
            <div class="flex items-center justify-between gap-3">
              <Label>Cover photo</Label>
              <Button
                v-if="form.coverPhotoId"
                type="button"
                variant="ghost"
                size="sm"
                data-testid="clear-cover-form"
                @click="clearCoverPhoto"
              >
                Clear
              </Button>
            </div>

            <p v-if="editingId === 'new'" class="text-sm text-muted-foreground">
              Save the album and add photos, then pick a cover from the Photos page or by editing again.
            </p>

            <template v-else>
              <p v-if="coverPhotosLoading" class="text-sm text-muted-foreground">Loading photos…</p>
              <p v-else-if="coverPhotosError" class="text-sm text-destructive">{{ coverPhotosError }}</p>
              <p v-else-if="coverPhotos.length === 0" class="text-sm text-muted-foreground">
                No photos in this album yet. Upload some from the Photos page, then choose a cover here.
              </p>
              <div v-else class="space-y-3">
                <div
                  v-if="selectedCoverPhoto && coverThumbSrc(selectedCoverPhoto)"
                  class="overflow-hidden rounded-lg border bg-muted"
                >
                  <img
                    :src="coverThumbSrc(selectedCoverPhoto)!"
                    :alt="selectedCoverPhoto.title ?? 'Cover photo'"
                    class="aspect-[16/9] w-full object-cover"
                    data-testid="cover-preview"
                  />
                </div>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4" data-testid="cover-picker">
                  <button
                    v-for="photo in coverPhotos"
                    :key="photo.id"
                    type="button"
                    class="group relative overflow-hidden rounded-md border bg-muted text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                      form.coverPhotoId === photo.id
                        ? 'border-foreground ring-2 ring-foreground'
                        : 'border-transparent hover:border-border'
                    "
                    data-testid="cover-option"
                    :disabled="!coverThumbSrc(photo)"
                    @click="chooseCoverPhoto(photo.id)"
                  >
                    <img
                      v-if="coverThumbSrc(photo)"
                      :src="coverThumbSrc(photo)!"
                      :alt="photo.title ?? 'Photo'"
                      class="aspect-square w-full object-cover"
                    />
                    <div v-else class="flex aspect-square items-center justify-center text-[10px] text-muted-foreground">
                      No preview
                    </div>
                    <span
                      v-if="form.coverPhotoId === photo.id"
                      class="absolute bottom-1 left-1 rounded bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background"
                    >
                      Cover
                    </span>
                    <span
                      v-else
                      class="absolute inset-x-0 bottom-0 bg-background/80 py-1 text-center text-[10px] text-muted-foreground opacity-0 transition group-hover:opacity-100"
                    >
                      Set cover
                    </span>
                  </button>
                </div>
              </div>
            </template>
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
