<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { adminApi } from '../../api/client'
import type { AdminTag } from '../../api/types'

const route = useRoute()
const router = useRouter()

const tags = ref<AdminTag[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const saving = ref(false)

const editing = ref<AdminTag | null>(null)
const editName = ref('')

async function load() {
  loading.value = true
  error.value = null
  try {
    tags.value = await adminApi.searchTags(search.value.trim() || undefined)
  } catch {
    error.value = 'Failed to load tags.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  search.value = typeof route.query.q === 'string' ? route.query.q : ''
  void load()
})

watch(
  () => route.query.q,
  (q) => {
    search.value = typeof q === 'string' ? q : ''
    void load()
  },
)

function submitSearch() {
  router.push({
    name: 'admin-tags',
    query: {
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
    },
  })
}

function openEdit(tag: AdminTag) {
  editing.value = tag
  editName.value = tag.name
}

function closeEdit() {
  editing.value = null
  editName.value = ''
}

async function saveTranslation() {
  if (!editing.value) return
  const name = editName.value.trim()
  if (!name) {
    error.value = 'Name cannot be empty.'
    return
  }

  saving.value = true
  error.value = null
  try {
    const updated = await adminApi.updateTag(editing.value.id, name)
    tags.value = tags.value.map((t) => (t.id === updated.id ? updated : t))
    closeEdit()
  } catch {
    error.value = 'Failed to save translation.'
  } finally {
    saving.value = false
  }
}

async function removeTag(tag: AdminTag) {
  if (!window.confirm(`Delete tag “${tag.name}”? It will be removed from all photos.`)) {
    return
  }

  error.value = null
  try {
    await adminApi.deleteTag(tag.id)
    tags.value = tags.value.filter((t) => t.id !== tag.id)
  } catch {
    error.value = 'Failed to delete tag.'
  }
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <p class="max-w-xl text-sm text-muted-foreground">
        Auto-suggested tags start in English (slug). Translate the display name for visitors —
        the slug stays stable so future uploads reuse the same tag.
      </p>

      <form class="flex gap-2" @submit.prevent="submitSearch">
        <Input
          v-model="search"
          type="search"
          placeholder="Search by name or slug…"
          class="w-56"
          data-testid="tags-search"
        />
        <Button type="submit" variant="outline" size="sm">Search</Button>
      </form>
    </div>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Loading tags…
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="!loading && tags.length > 0" class="admin-panel overflow-hidden rounded-xl">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Slug</TableHead>
            <TableHead class="w-24 text-right">Photos</TableHead>
            <TableHead class="w-40 text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="tag in tags" :key="tag.id" data-testid="tag-row">
            <TableCell class="font-medium" data-testid="tag-name">{{ tag.name }}</TableCell>
            <TableCell class="font-mono text-sm text-muted-foreground" data-testid="tag-slug">
              {{ tag.slug }}
            </TableCell>
            <TableCell class="text-right tabular-nums text-muted-foreground" data-testid="tag-photo-count">
              {{ tag.photoCount ?? 0 }}
            </TableCell>
            <TableCell class="text-right">
              <div class="flex justify-end gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  data-testid="tag-edit"
                  @click="openEdit(tag)"
                >
                  Translate
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  data-testid="tag-delete"
                  @click="removeTag(tag)"
                >
                  Delete
                </Button>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <div
      v-if="!loading && tags.length === 0"
      class="admin-upload-zone rounded-xl p-16 text-center text-sm text-muted-foreground"
      data-testid="tags-empty"
    >
      No tags yet. Upload photos to generate suggestions, or create tags when editing a photo.
    </div>

    <Dialog :open="editing !== null" @update:open="(open) => !open && closeEdit()">
      <DialogContent data-testid="tag-edit-dialog">
        <DialogHeader>
          <DialogTitle>Translate tag</DialogTitle>
          <DialogDescription v-if="editing">
            Slug <span class="font-mono">{{ editing.slug }}</span> stays unchanged.
          </DialogDescription>
        </DialogHeader>
        <form class="space-y-4" @submit.prevent="saveTranslation">
          <Input
            v-model="editName"
            type="text"
            placeholder="Display name"
            data-testid="tag-edit-name"
            autofocus
          />
          <DialogFooter>
            <Button type="button" variant="outline" @click="closeEdit">Cancel</Button>
            <Button type="submit" :disabled="saving" data-testid="tag-save">
              {{ saving ? 'Saving…' : 'Save' }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </section>
</template>
