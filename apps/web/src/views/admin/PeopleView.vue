<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ExternalLink } from '@lucide/vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
import { adminApi, ApiError, mediaUrl } from '../../api/client'
import type { AdminPerson, FaceSearchMatch, MergeSuggestion, PeopleScope, PeopleScopeCounts, PeopleSort } from '../../api/types'
import FaceGalleryScanPanel from '../../components/admin/FaceGalleryScanPanel.vue'
import PaginationBar from '../../components/PaginationBar.vue'
import { useAdminPersonSearch } from '../../composables/useAdminPersonSearch'

const route = useRoute()
const router = useRouter()

const people = ref<AdminPerson[]>([])
const mergeSuggestions = ref<MergeSuggestion[]>([])
const mergeSuggestionsLoading = ref(false)
const mergeSuggestionsError = ref<string | null>(null)
const mergeSuggestionsAnalyzed = ref(false)
const mergeSuggestionsMeta = ref<{
  unnamedClusterCount: number
  analyzedClusterCount: number
  truncated: boolean
  durationMs: number
} | null>(null)
const faceMatches = ref<FaceSearchMatch[]>([])
const faceSearchLoading = ref(false)
const faceSearchError = ref<string | null>(null)
const faceSearchPanel = ref<HTMLElement | null>(null)
const facePersonForm = reactive({ personId: '' })
const {
  query: facePersonQuery,
  results: facePersonResults,
  loading: facePersonLoading,
  error: facePersonSearchError,
  search: searchFacePeople,
  clear: clearFacePersonSearch,
} = useAdminPersonSearch()
let facePersonSearchTimer: ReturnType<typeof setTimeout> | null = null
const facePersonSearchOpen = ref(false)
const facePersonSearchRoot = ref<HTMLElement | null>(null)
const mergeLoadingId = ref<string | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const total = ref(0)
const perPage = 50
const scopeCounts = ref<PeopleScopeCounts>({ all: 0, named: 0, unnamed: 0, trashed: 0 })

const scope = computed<PeopleScope>(() => {
  const value = route.query.scope
  if (value === 'named' || value === 'unnamed' || value === 'trashed') return value
  return 'all'
})

const page = computed(() => Math.max(1, Number(route.query.page) || 1))

const sort = computed<PeopleSort>(() => {
  const value = route.query.sort
  if (value === 'faces' || value === 'newest') return value
  return 'name'
})

function peopleQuery(overrides: {
  scope?: PeopleScope
  q?: string
  page?: number
  sort?: PeopleSort
} = {}): Record<string, string> {
  const nextScope = overrides.scope ?? scope.value
  const nextQ = overrides.q ?? search.value.trim()
  const nextPage = overrides.page ?? page.value
  const nextSort = overrides.sort ?? sort.value
  const query: Record<string, string> = {}
  if (nextScope !== 'all') query.scope = nextScope
  if (nextQ) query.q = nextQ
  if (nextPage > 1) query.page = String(nextPage)
  if (nextSort !== 'name') query.sort = nextSort
  return query
}

async function loadMergeSuggestions() {
  if (scope.value !== 'unnamed') {
    return
  }
  mergeSuggestionsLoading.value = true
  mergeSuggestionsError.value = null
  try {
    const result = await adminApi.listMergeSuggestions()
    mergeSuggestions.value = result.data
    mergeSuggestionsMeta.value = result.meta
    mergeSuggestionsAnalyzed.value = true
  } catch (err) {
    mergeSuggestions.value = []
    mergeSuggestionsMeta.value = null
    mergeSuggestionsError.value =
      err instanceof ApiError ? err.message : 'Não foi possível analisar clusters.'
  } finally {
    mergeSuggestionsLoading.value = false
  }
}

function resetMergeSuggestions() {
  mergeSuggestions.value = []
  mergeSuggestionsMeta.value = null
  mergeSuggestionsError.value = null
  mergeSuggestionsAnalyzed.value = false
  mergeSuggestionsLoading.value = false
}

async function analyzeMergeSuggestions() {
  await loadMergeSuggestions()
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await adminApi.listPeople({
      scope: scope.value,
      q: search.value.trim() || undefined,
      page: page.value,
      perPage,
      sort: sort.value,
    })
    people.value = result.data
    total.value = result.meta.total
    scopeCounts.value = result.meta.counts
  } catch {
    error.value = 'Falha ao carregar pessoas.'
  } finally {
    loading.value = false
  }
}

watch([scope, () => route.query.q, () => route.query.sort, page], load)
watch(scope, (next, previous) => {
  if (next !== previous) {
    resetMergeSuggestions()
  }
})

watch(
  () => route.query.q,
  (q) => {
    search.value = typeof q === 'string' ? q : ''
  },
  { immediate: true },
)

function setScope(next: PeopleScope) {
  router.push({
    name: 'admin-people',
    query: peopleQuery({ scope: next, page: 1 }),
  })
}

function submitSearch() {
  router.push({
    name: 'admin-people',
    query: peopleQuery({ page: 1 }),
  })
}

function setPage(nextPage: number) {
  router.push({
    name: 'admin-people',
    query: peopleQuery({ page: nextPage }),
  })
}

function setSort(next: PeopleSort) {
  router.push({
    name: 'admin-people',
    query: peopleQuery({ sort: next, page: 1 }),
  })
}

function displayName(person: AdminPerson): string {
  if (person.isNamed && person.name) return person.name
  return person.faceCount === 1 ? '1 rosto' : `${person.faceCount} rostos`
}

function avatarSrc(person: AdminPerson): string | null {
  return mediaUrl(person.avatarCropPath)
}

function clusterLabel(faceCount: number): string {
  return faceCount === 1 ? '1 rosto' : `${faceCount} rostos`
}

function suggestionAvatar(path: string | null): string | null {
  return mediaUrl(path)
}

async function acceptMerge(suggestion: MergeSuggestion) {
  mergeLoadingId.value = suggestion.sourcePersonId
  try {
    await adminApi.mergePerson(suggestion.sourcePersonId, suggestion.targetPersonId)
    await load()
    if (mergeSuggestionsAnalyzed.value) {
      await loadMergeSuggestions()
    }
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Falha ao mesclar pessoas.'
  } finally {
    mergeLoadingId.value = null
  }
}

const trashBusyId = ref<string | null>(null)

async function restorePerson(person: AdminPerson) {
  trashBusyId.value = person.id
  try {
    await adminApi.restorePerson(person.id)
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Falha ao restaurar pessoa.'
  } finally {
    trashBusyId.value = null
  }
}

async function purgePerson(person: AdminPerson) {
  if (!window.confirm(`Excluir permanentemente ${displayName(person)} e todos os rostos?`)) {
    return
  }
  trashBusyId.value = person.id
  try {
    await adminApi.purgePerson(person.id)
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Falha ao excluir permanentemente.'
  } finally {
    trashBusyId.value = null
  }
}

async function onFaceSearch(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  faceSearchLoading.value = true
  faceSearchError.value = null
  faceMatches.value = []
  try {
    faceMatches.value = await adminApi.searchPeopleByFace(file)
  } catch (err) {
    faceSearchError.value =
      err instanceof ApiError ? err.message : 'Não foi possível buscar por rosto.'
  } finally {
    faceSearchLoading.value = false
  }
}

function onFacePersonSearchInput(event: Event) {
  facePersonQuery.value = (event.target as HTMLInputElement).value
  facePersonForm.personId = ''
  facePersonSearchOpen.value = true
  if (facePersonSearchTimer) clearTimeout(facePersonSearchTimer)
  facePersonSearchTimer = setTimeout(() => void searchFacePeople(), 200)
}

function onFacePersonSearchFocus() {
  facePersonSearchOpen.value = true
  if (facePersonSearchTimer) clearTimeout(facePersonSearchTimer)
  void searchFacePeople()
}

function closeFacePersonSearch() {
  facePersonSearchOpen.value = false
}

function onDocumentPointerDown(event: Event) {
  const root = facePersonSearchRoot.value
  if (root && !root.contains(event.target as Node)) closeFacePersonSearch()
}

function selectFacePerson(candidate: AdminPerson) {
  facePersonForm.personId = candidate.id
  facePersonQuery.value = candidate.name ?? ''
  facePersonResults.value = []
  closeFacePersonSearch()
}

async function runFaceSearchByPerson(personId: string) {
  faceSearchLoading.value = true
  faceSearchError.value = null
  faceMatches.value = []
  try {
    faceMatches.value = await adminApi.searchPeopleByPerson(personId)
    faceSearchPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  } catch (err) {
    faceSearchError.value =
      err instanceof ApiError ? err.message : 'Não foi possível buscar por rosto.'
  } finally {
    faceSearchLoading.value = false
  }
}

async function onFacePersonSearchSubmit() {
  if (!facePersonForm.personId) {
    faceSearchError.value = 'Escolha uma pessoa para buscar.'
    return
  }
  await runFaceSearchByPerson(facePersonForm.personId)
}

onMounted(() => {
  void load()
  document.addEventListener('pointerdown', onDocumentPointerDown)
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  if (facePersonSearchTimer) clearTimeout(facePersonSearchTimer)
  clearFacePersonSearch()
})
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div class="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          :variant="scope === 'all' ? 'default' : 'outline'"
          data-testid="scope-all"
          @click="setScope('all')"
        >
          Todos
          <span class="ml-1 tabular-nums opacity-80">({{ scopeCounts.all }})</span>
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="scope === 'named' ? 'default' : 'outline'"
          data-testid="scope-named"
          @click="setScope('named')"
        >
          Nomeadas
          <span class="ml-1 tabular-nums opacity-80">({{ scopeCounts.named }})</span>
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="scope === 'unnamed' ? 'default' : 'outline'"
          data-testid="scope-unnamed"
          @click="setScope('unnamed')"
        >
          Sem nome
          <span class="ml-1 tabular-nums opacity-80">({{ scopeCounts.unnamed }})</span>
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="scope === 'trashed' ? 'default' : 'outline'"
          data-testid="scope-trashed"
          @click="setScope('trashed')"
        >
          Lixeira
          <span class="ml-1 tabular-nums opacity-80">({{ scopeCounts.trashed }})</span>
        </Button>
      </div>

      <form class="flex flex-wrap items-center gap-2" @submit.prevent="submitSearch">
        <Input
          v-model="search"
          type="search"
          placeholder="Buscar por nome…"
          class="w-56"
          data-testid="people-search"
        />
        <Select :model-value="sort" @update:model-value="setSort($event as PeopleSort)">
          <SelectTrigger class="w-[160px]" data-testid="people-sort">
            <SelectValue placeholder="Ordenar" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="name">Nome</SelectItem>
            <SelectItem value="faces">Mais rostos</SelectItem>
            <SelectItem value="newest">Mais recentes</SelectItem>
          </SelectContent>
        </Select>
        <Button type="submit" variant="outline" size="sm">Buscar</Button>
      </form>
    </div>

    <div
      v-if="scope !== 'trashed'"
      ref="faceSearchPanel"
      class="admin-panel space-y-3 rounded-xl p-4"
      data-testid="face-search-panel"
    >
      <div>
        <h2 class="text-sm font-medium">Busca por rosto</h2>
        <p class="text-sm text-muted-foreground">
          Envie um recorte com um rosto ou escolha uma pessoa existente para encontrar semelhantes.
        </p>
      </div>
      <Input
        type="file"
        accept="image/jpeg,image/png,image/webp"
        data-testid="face-search-input"
        @change="onFaceSearch"
      />
      <div class="space-y-2 border-t border-border/60 pt-3">
        <Label for="face-person-search">Ou buscar a partir de uma pessoa</Label>
        <div class="flex flex-wrap items-center gap-2">
          <div ref="facePersonSearchRoot" class="relative min-w-0 max-w-md flex-1">
            <Input
              id="face-person-search"
              v-model="facePersonQuery"
              type="search"
              placeholder="Buscar pessoa nomeada…"
              :disabled="faceSearchLoading"
              autocomplete="off"
              data-testid="face-person-search"
              @focus="onFacePersonSearchFocus"
              @input="onFacePersonSearchInput"
              @keydown.esc="closeFacePersonSearch"
            />
            <ul
              v-if="facePersonSearchOpen && facePersonResults.length > 0"
              class="admin-suggestions"
              data-testid="face-person-suggestions"
            >
              <li v-for="candidate in facePersonResults" :key="candidate.id">
                <button
                  type="button"
                  class="admin-suggestion"
                  data-testid="face-person-suggestion"
                  @click="selectFacePerson(candidate)"
                >
                  {{ candidate.name }}
                </button>
              </li>
            </ul>
          </div>
          <Button
            type="button"
            size="sm"
            variant="outline"
            :disabled="faceSearchLoading || !facePersonForm.personId"
            data-testid="face-person-search-submit"
            @click="onFacePersonSearchSubmit"
          >
            Buscar
          </Button>
        </div>
        <p v-if="facePersonLoading" class="text-xs text-muted-foreground">Buscando pessoas…</p>
        <p v-else-if="facePersonSearchError" class="text-sm text-destructive">
          {{ facePersonSearchError }}
        </p>
      </div>
      <p v-if="faceSearchLoading" class="text-sm text-muted-foreground">Buscando…</p>
      <Alert v-if="faceSearchError" variant="destructive">
        <AlertDescription>{{ faceSearchError }}</AlertDescription>
      </Alert>
      <ul v-if="faceMatches.length > 0" class="space-y-2" data-testid="face-search-results">
        <li
          v-for="match in faceMatches"
          :key="match.personId"
          class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
        >
          <div class="flex items-center gap-3">
            <img
              v-if="mediaUrl(match.avatarCropPath)"
              :src="mediaUrl(match.avatarCropPath)!"
              alt=""
              class="size-10 rounded-md object-cover bg-muted"
            />
            <div>
              <p class="font-medium">{{ match.name ?? 'Sem nome' }}</p>
              <p class="text-xs text-muted-foreground">distância {{ match.distance.toFixed(3) }}</p>
            </div>
          </div>
          <Button as-child variant="outline" size="sm">
            <RouterLink :to="{ name: 'admin-person-edit', params: { id: match.personId } }">
              Abrir
            </RouterLink>
          </Button>
        </li>
      </ul>
    </div>

    <FaceGalleryScanPanel v-if="scope !== 'trashed'" />

    <div
      v-if="scope === 'unnamed'"
      class="admin-panel space-y-3 rounded-xl p-4"
      data-testid="merge-suggestions-panel"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-medium">Sugestões de mesclagem</h2>
          <p class="text-sm text-muted-foreground">
            Compare clusters sem nome usando um rosto representativo por agrupamento.
          </p>
        </div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          data-testid="analyze-merge-suggestions"
          :disabled="mergeSuggestionsLoading"
          @click="analyzeMergeSuggestions"
        >
          {{ mergeSuggestionsAnalyzed ? 'Analisar novamente' : 'Analisar clusters' }}
        </Button>
      </div>

      <p v-if="mergeSuggestionsLoading" class="text-sm text-muted-foreground">Analisando clusters…</p>

      <Alert v-if="mergeSuggestionsError" variant="destructive">
        <AlertDescription>{{ mergeSuggestionsError }}</AlertDescription>
      </Alert>

      <p
        v-else-if="mergeSuggestionsAnalyzed && mergeSuggestions.length === 0"
        class="text-sm text-muted-foreground"
        data-testid="merge-suggestions-empty"
      >
        Nenhum par similar encontrado.
      </p>

      <p
        v-if="mergeSuggestionsMeta && mergeSuggestionsAnalyzed"
        class="text-xs text-muted-foreground"
        data-testid="merge-suggestions-meta"
      >
        {{ mergeSuggestionsMeta.analyzedClusterCount }} de
        {{ mergeSuggestionsMeta.unnamedClusterCount }} clusters analisados em
        {{ mergeSuggestionsMeta.durationMs }} ms
        <span v-if="mergeSuggestionsMeta.truncated">
          (limitado aos clusters com mais rostos)
        </span>
      </p>

      <ul
        v-if="mergeSuggestions.length > 0"
        class="space-y-2"
        data-testid="merge-suggestions"
      >
        <li
          v-for="suggestion in mergeSuggestions"
          :key="`${suggestion.sourcePersonId}-${suggestion.targetPersonId}`"
          class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border px-3 py-3"
        >
          <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
              <RouterLink
                :to="{ name: 'admin-person-edit', params: { id: suggestion.sourcePersonId } }"
                class="flex items-center gap-2 rounded-md hover:opacity-80"
                data-testid="merge-suggestion-source"
              >
                <img
                  v-if="suggestionAvatar(suggestion.sourceAvatarCropPath)"
                  :src="suggestionAvatar(suggestion.sourceAvatarCropPath)!"
                  alt=""
                  class="size-12 rounded-md object-cover bg-muted"
                />
                <div
                  v-else
                  class="flex size-12 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                >
                  —
                </div>
                <span class="text-sm font-medium">{{ clusterLabel(suggestion.faceCountA) }}</span>
              </RouterLink>
            </div>

            <span class="text-muted-foreground">→</span>

            <div class="flex items-center gap-2">
              <RouterLink
                :to="{ name: 'admin-person-edit', params: { id: suggestion.targetPersonId } }"
                class="flex items-center gap-2 rounded-md hover:opacity-80"
                data-testid="merge-suggestion-target"
              >
                <img
                  v-if="suggestionAvatar(suggestion.targetAvatarCropPath)"
                  :src="suggestionAvatar(suggestion.targetAvatarCropPath)!"
                  alt=""
                  class="size-12 rounded-md object-cover bg-muted"
                />
                <div
                  v-else
                  class="flex size-12 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                >
                  —
                </div>
                <span class="text-sm font-medium">{{ clusterLabel(suggestion.faceCountB) }}</span>
              </RouterLink>
            </div>

            <span class="text-xs text-muted-foreground">distância {{ suggestion.distance.toFixed(3) }}</span>
          </div>

          <Button
            type="button"
            size="sm"
            :disabled="mergeLoadingId === suggestion.sourcePersonId"
            data-testid="merge-suggestion-button"
            @click="acceptMerge(suggestion)"
          >
            Mesclar
          </Button>
        </li>
      </ul>
    </div>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Carregando pessoas…
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="!loading && people.length > 0" class="admin-panel overflow-hidden rounded-xl">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-16">Avatar</TableHead>
            <TableHead>Nome</TableHead>
            <TableHead class="w-28">Status</TableHead>
            <TableHead class="w-24 text-right">Rostos</TableHead>
            <TableHead class="w-52 text-right">Ações</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="person in people"
            :key="person.id"
            data-testid="person-row"
            :class="scope !== 'trashed' ? 'cursor-pointer' : undefined"
            @click="scope !== 'trashed' && router.push({ name: 'admin-person-edit', params: { id: person.id } })"
          >
            <TableCell>
              <img
                v-if="avatarSrc(person)"
                :src="avatarSrc(person)!"
                alt=""
                class="size-10 rounded-md object-cover bg-muted"
                data-testid="person-avatar"
              />
              <div
                v-else
                class="flex size-10 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                data-testid="person-avatar-empty"
              >
                —
              </div>
            </TableCell>
            <TableCell>
              <RouterLink
                :to="{ name: 'admin-person-edit', params: { id: person.id } }"
                class="font-medium text-foreground hover:underline"
                @click.stop
              >
                {{ displayName(person) }}
              </RouterLink>
            </TableCell>
            <TableCell>
              <Badge :variant="person.isNamed ? 'default' : 'secondary'">
                {{ person.isNamed ? 'Nomeada' : 'Sem nome' }}
              </Badge>
            </TableCell>
            <TableCell class="text-right tabular-nums text-muted-foreground">
              {{ person.faceCount }}
            </TableCell>
            <TableCell class="text-right" @click.stop>
              <div v-if="scope === 'trashed'" class="flex justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="trashBusyId === person.id"
                  data-testid="person-restore"
                  @click="restorePerson(person)"
                >
                  Restaurar
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  class="admin-btn-danger-solid"
                  :disabled="trashBusyId === person.id"
                  data-testid="person-purge"
                  @click="purgePerson(person)"
                >
                  Excluir
                </Button>
              </div>
              <div v-else class="flex flex-wrap justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="faceSearchLoading"
                  data-testid="person-similar"
                  @click="runFaceSearchByPerson(person.id)"
                >
                  Semelhantes
                </Button>
                <Button as-child variant="outline" size="sm">
                  <RouterLink
                    :to="{ name: 'person', params: { id: person.id } }"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="person-public-link"
                    title="Ver fotos no site público"
                  >
                    <ExternalLink class="size-3.5" />
                    Ver no site
                  </RouterLink>
                </Button>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <PaginationBar
      :page="page"
      :total="total"
      :per-page="perPage"
      @update:page="setPage"
    />

    <div
      v-if="!loading && people.length === 0"
      class="admin-upload-zone space-y-4 rounded-xl p-16 text-center text-sm text-muted-foreground"
      data-testid="people-empty"
    >
      <p>Nenhuma pessoa corresponde a este filtro.</p>
      <Button
        v-if="page > 1"
        type="button"
        variant="outline"
        size="sm"
        data-testid="people-empty-previous"
        @click="setPage(page - 1)"
      >
        Voltar à página anterior
      </Button>
    </div>
  </section>
</template>
