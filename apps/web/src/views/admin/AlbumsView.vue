<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { adminApi, photoDisplayUrl } from '../../api/client'
import type { AdminAlbum } from '../../api/types'
import AlbumFormDialog from '../../components/admin/AlbumFormDialog.vue'
import PaginationBar from '../../components/PaginationBar.vue'

type AlbumVisibilityFilter = 'all' | AdminAlbum['visibility']

const route = useRoute()
const router = useRouter()

const albums = ref<AdminAlbum[]>([])
const total = ref(0)
const perPage = 24
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

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const filtersActive = computed(
  () =>
    visibilityFilter.value !== 'all' ||
    search.value.trim() !== '' ||
    dateFrom.value.trim() !== '' ||
    dateTo.value.trim() !== '' ||
    locationFilter.value.trim() !== '',
)

function albumCoverSrc(album: AdminAlbum): string | null {
  return album.cover ? photoDisplayUrl(album.cover) : null
}

function albumFilterQuery(overrides: {
  visibility?: AlbumVisibilityFilter
  q?: string
  from?: string
  to?: string
  location?: string
  page?: number
} = {}) {
  const nextVisibility = overrides.visibility ?? visibilityFilter.value
  const nextSearch = overrides.q ?? search.value
  const nextFrom = overrides.from ?? dateFrom.value
  const nextTo = overrides.to ?? dateTo.value
  const nextLocation = overrides.location ?? locationFilter.value
  const nextPage = overrides.page ?? page.value
  return {
    ...(nextVisibility !== 'all' ? { visibility: nextVisibility } : {}),
    ...(nextSearch.trim() ? { q: nextSearch.trim() } : {}),
    ...(nextFrom.trim() ? { from: nextFrom.trim() } : {}),
    ...(nextTo.trim() ? { to: nextTo.trim() } : {}),
    ...(nextLocation.trim() ? { location: nextLocation.trim() } : {}),
    ...(nextPage > 1 ? { page: String(nextPage) } : {}),
  }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await adminApi.listAlbums({
      page: page.value,
      perPage,
      visibility: visibilityFilter.value === 'all' ? undefined : visibilityFilter.value,
      q: typeof route.query.q === 'string' ? route.query.q : undefined,
      from: typeof route.query.from === 'string' ? route.query.from : undefined,
      to: typeof route.query.to === 'string' ? route.query.to : undefined,
      location: typeof route.query.location === 'string' ? route.query.location : undefined,
    })
    albums.value = result.data
    total.value = result.meta.total
  } catch {
    error.value = 'Falha ao carregar álbuns.'
  } finally {
    loading.value = false
  }
}

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

watch(
  () => [
    route.query.page,
    route.query.visibility,
    route.query.q,
    route.query.from,
    route.query.to,
    route.query.location,
  ],
  () => {
    void load()
  },
  { immediate: true },
)

function setVisibilityFilter(next: AlbumVisibilityFilter) {
  router.push({
    name: 'admin-albums',
    query: albumFilterQuery({ visibility: next, page: 1 }),
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
      page: 1,
    }),
  })
}

function setPage(next: number) {
  router.push({
    name: 'admin-albums',
    query: albumFilterQuery({ page: next }),
  })
}

const editingId = ref<string | 'new' | null>(null)

const editingAlbum = computed(() =>
  editingId.value && editingId.value !== 'new'
    ? (albums.value.find((album) => album.id === editingId.value) ?? null)
    : null,
)

const formDialogOpen = computed({
  get: () => editingId.value !== null,
  set: (open: boolean) => {
    if (!open) editingId.value = null
  },
})

function startCreate() {
  editingId.value = 'new'
}

function badgeVariant(visibility: AdminAlbum['visibility']) {
  if (visibility === 'public') return 'default'
  if (visibility === 'unlisted') return 'secondary'
  return 'outline'
}

function visibilityLabel(visibility: AdminAlbum['visibility']): string {
  if (visibility === 'public') return 'Público'
  if (visibility === 'unlisted') return 'Não listado'
  return 'Privado'
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
          Todos
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'public' ? 'default' : 'outline'"
          data-testid="visibility-public"
          @click="setVisibilityFilter('public')"
        >
          Público
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'unlisted' ? 'default' : 'outline'"
          data-testid="visibility-unlisted"
          @click="setVisibilityFilter('unlisted')"
        >
          Não listado
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="visibilityFilter === 'private' ? 'default' : 'outline'"
          data-testid="visibility-private"
          @click="setVisibilityFilter('private')"
        >
          Privado
        </Button>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" class="h-9 px-4" @click="startCreate()">Novo álbum</Button>
      </div>
    </div>

    <form
      class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3 sm:flex-row sm:flex-wrap sm:items-end"
      data-testid="albums-filters"
      @submit.prevent="submitSearch"
    >
      <div class="grid gap-1.5">
        <Label for="albums-from" class="text-xs text-muted-foreground">De</Label>
        <Input
          id="albums-from"
          v-model="dateFrom"
          type="date"
          class="w-full sm:w-40"
          data-testid="albums-from"
        />
      </div>
      <div class="grid gap-1.5">
        <Label for="albums-to" class="text-xs text-muted-foreground">Até</Label>
        <Input
          id="albums-to"
          v-model="dateTo"
          type="date"
          class="w-full sm:w-40"
          data-testid="albums-to"
        />
      </div>
      <div class="grid min-w-[12rem] flex-1 gap-1.5">
        <Label for="albums-location" class="text-xs text-muted-foreground">Local</Label>
        <Input
          id="albums-location"
          v-model="locationFilter"
          type="search"
          placeholder="Nome, cidade ou país…"
          data-testid="albums-location"
        />
      </div>
      <div class="grid min-w-[12rem] flex-1 gap-1.5">
        <Label for="albums-search" class="text-xs text-muted-foreground">Título / slug</Label>
        <Input
          id="albums-search"
          v-model="search"
          type="search"
          placeholder="Buscar título ou slug…"
          data-testid="albums-search"
        />
      </div>
      <Button type="submit" variant="outline" size="sm" class="h-9">Aplicar filtros</Button>
    </form>

    <p class="text-sm text-muted-foreground">
      {{ total === 1 ? '1 álbum' : `${total} álbuns` }}
      <span v-if="filtersActive">(filtrado)</span>
    </p>

    <div v-if="loading" class="admin-panel rounded-2xl p-16 text-center text-sm text-muted-foreground">
      Carregando álbuns…
    </div>

    <Alert v-else-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div
      v-else-if="albums.length === 0"
      class="admin-panel rounded-2xl p-16 text-center"
      data-testid="albums-empty"
    >
      <p class="text-sm text-muted-foreground">
        {{ filtersActive ? 'Nenhum álbum corresponde a este filtro.' : 'Nenhum álbum ainda.' }}
      </p>
      <Button
        v-if="!filtersActive"
        type="button"
        variant="link"
        class="mt-2"
        @click="startCreate()"
      >
        Crie seu primeiro álbum
      </Button>
    </div>

    <div v-else class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
      <article
        v-for="album in albums"
        :key="album.id"
        data-testid="album-row"
        class="admin-photo-tile group min-w-0 overflow-hidden rounded-2xl"
      >
        <RouterLink
          :to="{ name: 'admin-album-photos', params: { albumId: album.id } }"
          class="relative block aspect-square bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <img
            v-if="albumCoverSrc(album)"
            :src="albumCoverSrc(album)!"
            :alt="album.title"
            class="size-full object-cover transition duration-300 group-hover:scale-[1.03]"
          />
          <div
            v-else
            class="flex size-full items-center justify-center px-3 text-center text-3xl font-medium text-muted-foreground"
          >
            {{ album.title.slice(0, 1).toUpperCase() }}
          </div>
          <div
            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 pb-2.5 pt-8"
          >
            <p class="truncate text-sm font-medium text-white">{{ album.title }}</p>
            <p class="truncate text-[11px] text-white/75">
              <span v-if="album.parentId">Subálbum · </span>
              {{ album.photoCount }} foto{{ album.photoCount === 1 ? '' : 's' }}
              <span v-if="album.childCount > 0">
                · {{ album.childCount }} subálbum{{ album.childCount === 1 ? '' : 's' }}
              </span>
            </p>
          </div>
          <Badge
            :variant="badgeVariant(album.visibility)"
            class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-[10px] font-medium"
          >
            {{ visibilityLabel(album.visibility) }}
          </Badge>
        </RouterLink>
      </article>
    </div>

    <PaginationBar :page="page" :total="total" :per-page="perPage" @update:page="setPage" />

    <AlbumFormDialog
      v-model:open="formDialogOpen"
      :editing-id="editingId"
      :album="editingAlbum"
      :create-parent-id="null"
      :show-parent-select="false"
      :parent-options="[]"
      @saved="load"
    />
  </section>
</template>
