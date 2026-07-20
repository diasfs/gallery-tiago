<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
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

async function remove(album: AdminAlbum) {
  if (
    !confirm(
      `Delete "${album.title}" and all of its sub-albums and photos? This cannot be undone.`,
    )
  ) {
    return
  }
  try {
    await adminApi.deleteAlbum(album.id)
    await load()
  } catch {
    error.value = 'Delete failed.'
  }
}
</script>

<template>
  <section class="albums">
    <header class="albums__header">
      <h1>Albums</h1>
      <div class="albums__header-actions">
        <RouterLink to="/admin/people/unnamed">Unnamed people</RouterLink>
        <button type="button" @click="startCreate()">New album</button>
      </div>
    </header>

    <p v-if="loading">Loading albums…</p>
    <p v-else-if="error" class="albums__error">{{ error }}</p>

    <table v-else class="albums__table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Visibility</th>
          <th>Order</th>
          <th>Photos</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="{ album, depth } in flatAlbums" :key="album.id">
          <td :style="{ paddingLeft: `${depth * 1.5}rem` }">{{ album.title }}</td>
          <td><span class="badge" :class="`badge--${album.visibility}`">{{ album.visibility }}</span></td>
          <td>{{ album.sortOrder }}</td>
          <td>{{ album.photoCount }}</td>
          <td class="albums__row-actions">
            <RouterLink :to="{ name: 'admin-album-photos', params: { albumId: album.id } }">Photos</RouterLink>
            <button type="button" @click="startCreate(album.id)">+ Sub-album</button>
            <button type="button" @click="startEdit(album)">Edit</button>
            <button type="button" class="danger" @click="remove(album)">Delete</button>
          </td>
        </tr>
        <tr v-if="flatAlbums.length === 0">
          <td colspan="5">No albums yet.</td>
        </tr>
      </tbody>
    </table>

    <form v-if="editingId !== null" class="album-form" @submit.prevent="submit">
      <h2>{{ editingId === 'new' ? 'New album' : 'Edit album' }}</h2>

      <label>
        <span>Title</span>
        <input v-model="form.title" required />
      </label>
      <label>
        <span>Slug</span>
        <input v-model="form.slug" required />
      </label>
      <label>
        <span>Description</span>
        <textarea v-model="form.description" rows="2"></textarea>
      </label>
      <label>
        <span>Visibility</span>
        <select v-model="form.visibility">
          <option value="public">Public</option>
          <option value="unlisted">Unlisted</option>
          <option value="private">Private</option>
        </select>
      </label>
      <label>
        <span>Sort order</span>
        <input v-model.number="form.sortOrder" type="number" />
      </label>
      <label>
        <span>Parent album</span>
        <select v-model="form.parentId">
          <option value="">(root)</option>
          <option v-for="album in albums" :key="album.id" :value="album.id" :disabled="album.id === editingId">
            {{ album.title }}
          </option>
        </select>
      </label>
      <label>
        <span>Cover photo id</span>
        <input v-model="form.coverPhotoId" placeholder="optional photo UUID" />
      </label>

      <p v-if="formError" class="albums__error">{{ formError }}</p>

      <div class="album-form__actions">
        <button type="submit">Save</button>
        <button type="button" @click="cancelEdit">Cancel</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.albums__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.albums__header-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.albums__error {
  color: #f87171;
}

.albums__table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 1.5rem;
}

.albums__table th,
.albums__table td {
  text-align: left;
  padding: 0.5rem 0.6rem;
  border-bottom: 1px solid #262626;
  font-size: 0.9rem;
}

.albums__row-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.badge {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
  background: #1a1a1a;
}

.badge--public {
  color: #4ade80;
}

.badge--unlisted {
  color: #facc15;
}

.badge--private {
  color: #f87171;
}

.album-form {
  margin-top: 2rem;
  padding: 1.5rem;
  border: 1px solid #262626;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  max-width: 480px;
}

.album-form label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.85rem;
}

.album-form input,
.album-form select,
.album-form textarea {
  padding: 0.45rem 0.6rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #111;
  color: inherit;
  font: inherit;
}

.album-form__actions {
  display: flex;
  gap: 0.6rem;
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

button.danger {
  color: #f87171;
  border-color: #7f1d1d;
}

button[type='submit'] {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
  font-weight: 600;
}
</style>
