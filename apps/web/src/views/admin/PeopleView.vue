<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ExternalLink } from '@lucide/vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, FaceSearchMatch, MergeSuggestion, PeopleScope } from '../../api/types'
import PaginationBar from '../../components/PaginationBar.vue'

const route = useRoute()
const router = useRouter()

const people = ref<AdminPerson[]>([])
const mergeSuggestions = ref<MergeSuggestion[]>([])
const faceMatches = ref<FaceSearchMatch[]>([])
const faceSearchLoading = ref(false)
const faceSearchError = ref<string | null>(null)
const mergeLoadingId = ref<string | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const total = ref(0)
const perPage = 50

const scope = computed<PeopleScope>(() => {
  const value = route.query.scope
  if (value === 'named' || value === 'unnamed') return value
  return 'all'
})

const page = computed(() => Math.max(1, Number(route.query.page) || 1))

async function loadMergeSuggestions() {
  if (scope.value !== 'unnamed') {
    mergeSuggestions.value = []
    return
  }
  try {
    mergeSuggestions.value = await adminApi.listMergeSuggestions()
  } catch {
    mergeSuggestions.value = []
  }
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
    })
    people.value = result.data
    total.value = result.meta.total
    await loadMergeSuggestions()
  } catch {
    error.value = 'Falha ao carregar pessoas.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([scope, () => route.query.q, page], load)

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
    query: {
      ...(next !== 'all' ? { scope: next } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
    },
  })
}

function submitSearch() {
  router.push({
    name: 'admin-people',
    query: {
      ...(scope.value !== 'all' ? { scope: scope.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
    },
  })
}

function setPage(nextPage: number) {
  router.push({
    name: 'admin-people',
    query: {
      ...(scope.value !== 'all' ? { scope: scope.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(nextPage > 1 ? { page: String(nextPage) } : {}),
    },
  })
}

function displayName(person: AdminPerson): string {
  return person.isNamed && person.name ? person.name : 'Agrupamento sem nome'
}

function avatarSrc(person: AdminPerson): string | null {
  return mediaUrl(person.avatarCropPath)
}

function personLabel(id: string): string {
  const person = people.value.find((entry) => entry.id === id)
  if (!person) return id.slice(0, 8)
  return displayName(person)
}

async function acceptMerge(suggestion: MergeSuggestion) {
  mergeLoadingId.value = suggestion.sourcePersonId
  try {
    await adminApi.mergePerson(suggestion.sourcePersonId, suggestion.targetPersonId)
    await load()
  } catch {
    error.value = 'Falha ao mesclar pessoas.'
  } finally {
    mergeLoadingId.value = null
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
  } catch {
    faceSearchError.value = 'Não foi possível buscar por rosto.'
  } finally {
    faceSearchLoading.value = false
  }
}
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
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="scope === 'named' ? 'default' : 'outline'"
          data-testid="scope-named"
          @click="setScope('named')"
        >
          Nomeadas
        </Button>
        <Button
          type="button"
          size="sm"
          :variant="scope === 'unnamed' ? 'default' : 'outline'"
          data-testid="scope-unnamed"
          @click="setScope('unnamed')"
        >
          Sem nome
        </Button>
      </div>

      <form class="flex gap-2" @submit.prevent="submitSearch">
        <Input
          v-model="search"
          type="search"
          placeholder="Buscar por nome…"
          class="w-56"
          data-testid="people-search"
        />
        <Button type="submit" variant="outline" size="sm">Buscar</Button>
      </form>
    </div>

    <div class="admin-panel space-y-3 rounded-xl p-4">
      <div>
        <h2 class="text-sm font-medium">Busca por rosto</h2>
        <p class="text-sm text-muted-foreground">Envie um recorte com um rosto para encontrar pessoas parecidas.</p>
      </div>
      <Input
        type="file"
        accept="image/jpeg,image/png,image/webp"
        data-testid="face-search-input"
        @change="onFaceSearch"
      />
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

    <div
      v-if="scope === 'unnamed' && mergeSuggestions.length > 0"
      class="admin-panel space-y-3 rounded-xl p-4"
      data-testid="merge-suggestions"
    >
      <div>
        <h2 class="text-sm font-medium">Sugestões de mesclagem</h2>
        <p class="text-sm text-muted-foreground">Clusters sem nome com rostos muito parecidos.</p>
      </div>
      <ul class="space-y-2">
        <li
          v-for="suggestion in mergeSuggestions"
          :key="`${suggestion.sourcePersonId}-${suggestion.targetPersonId}`"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
        >
          <p class="text-sm">
            {{ personLabel(suggestion.sourcePersonId) }}
            <span class="text-muted-foreground">→</span>
            {{ personLabel(suggestion.targetPersonId) }}
            <span class="text-muted-foreground">({{ suggestion.distance.toFixed(3) }})</span>
          </p>
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
            <TableHead class="w-36 text-right">Ações</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="person in people"
            :key="person.id"
            data-testid="person-row"
            class="cursor-pointer"
            @click="router.push({ name: 'admin-person-edit', params: { id: person.id } })"
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
