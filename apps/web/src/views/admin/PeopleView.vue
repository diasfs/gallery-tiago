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
import type { AdminPerson, PeopleScope } from '../../api/types'

const route = useRoute()
const router = useRouter()

const people = ref<AdminPerson[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')

const scope = computed<PeopleScope>(() => {
  const value = route.query.scope
  if (value === 'named' || value === 'unnamed') return value
  return 'all'
})

async function load() {
  loading.value = true
  error.value = null
  try {
    people.value = await adminApi.listPeople(scope.value, search.value.trim() || undefined)
  } catch {
    error.value = 'Falha ao carregar pessoas.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([scope, () => route.query.q], load)

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

function displayName(person: AdminPerson): string {
  return person.isNamed && person.name ? person.name : 'Agrupamento sem nome'
}

function avatarSrc(person: AdminPerson): string | null {
  return mediaUrl(person.avatarCropPath)
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

    <div
      v-if="!loading && people.length === 0"
      class="admin-upload-zone rounded-xl p-16 text-center text-sm text-muted-foreground"
      data-testid="people-empty"
    >
      Nenhuma pessoa corresponde a este filtro.
    </div>
  </section>
</template>
