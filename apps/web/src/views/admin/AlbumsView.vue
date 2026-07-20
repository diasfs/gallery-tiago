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
import type { AdminAlbum } from '../../api/types'

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
}>({
  title: '',
  slug: '',
  description: '',
  visibility: 'private',
  sortOrder: 0,
  parentId: '',
  coverPhotoId: '',
})

function resetForm() {
  form.title = ''
  form.slug = ''
  form.description = ''
  form.visibility = 'private'
  form.sortOrder = 0
  form.parentId = ''
  form.coverPhotoId = ''
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
  <section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-muted-foreground">
        Organize albums, set their visibility, and manage nested collections.
      </p>
      <Button type="button" @click="startCreate()">New album</Button>
    </div>

    <div v-if="loading" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
      Loading albums…
    </div>

    <Alert v-else-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-else class="overflow-hidden rounded-lg border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Title</TableHead>
            <TableHead>Visibility</TableHead>
            <TableHead class="w-20 text-right">Order</TableHead>
            <TableHead class="w-20 text-right">Photos</TableHead>
            <TableHead class="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="{ album, depth } in flatAlbums" :key="album.id">
            <TableCell class="font-medium">
              <span class="inline-flex items-center" :style="{ paddingLeft: `${depth * 1.5}rem` }">
                <span v-if="depth > 0" class="mr-2 text-muted-foreground" aria-hidden="true">↳</span>
                {{ album.title }}
              </span>
            </TableCell>
            <TableCell>
              <Badge :variant="badgeVariant(album.visibility)" class="capitalize">
                {{ album.visibility }}
              </Badge>
            </TableCell>
            <TableCell class="text-right tabular-nums">{{ album.sortOrder }}</TableCell>
            <TableCell class="text-right tabular-nums">{{ album.photoCount }}</TableCell>
            <TableCell>
              <div class="flex flex-wrap justify-end gap-1">
                <Button as-child variant="ghost" size="sm">
                  <RouterLink :to="{ name: 'admin-album-photos', params: { albumId: album.id } }">
                    Photos
                  </RouterLink>
                </Button>
                <Button type="button" variant="ghost" size="sm" @click="startCreate(album.id)">
                  Add sub-album
                </Button>
                <Button type="button" variant="ghost" size="sm" @click="startEdit(album)">
                  Edit
                </Button>
                <Button type="button" variant="ghost" size="sm" class="text-destructive" @click="remove(album)">
                  Delete
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-if="flatAlbums.length === 0">
            <TableCell colspan="5" class="h-28 text-center text-muted-foreground">
              No albums yet. Create one to start organizing photos.
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

          <DialogFooter>
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
        <DialogFooter>
          <Button type="button" variant="outline" @click="deletingAlbum = null">Cancel</Button>
          <Button type="button" variant="destructive" @click="confirmDelete">Delete album</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
