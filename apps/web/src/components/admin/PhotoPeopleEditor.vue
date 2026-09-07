<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, PersonSummary } from '../../api/types'
import { useAdminPersonSearch } from '../../composables/useAdminPersonSearch'

const props = defineProps<{ photoId: string }>()
const people = defineModel<PersonSummary[]>('people', { required: true })
const emit = defineEmits<{ error: [message: string] }>()

const {
  query: peopleQuery,
  results: peopleResults,
  loading: peopleSearchLoading,
  search: searchPeople,
  clear: clearPeopleSearch,
} = useAdminPersonSearch()
const peopleBusy = ref(false)
let peopleSearchTimer: ReturnType<typeof setTimeout> | null = null
const peopleSearchOpen = ref(false)
const peopleSearchRoot = ref<HTMLElement | null>(null)

function onPeopleSearchInput(event: Event) {
  peopleQuery.value = (event.target as HTMLInputElement).value
  peopleSearchOpen.value = true
  if (peopleSearchTimer) clearTimeout(peopleSearchTimer)
  peopleSearchTimer = setTimeout(() => void searchPeople(), 200)
}

function onPeopleSearchFocus() {
  peopleSearchOpen.value = true
  if (peopleSearchTimer) clearTimeout(peopleSearchTimer)
  void searchPeople()
}

function closePeopleSearch() {
  peopleSearchOpen.value = false
}

function onDocumentPointerDown(event: Event) {
  const root = peopleSearchRoot.value
  if (root && !root.contains(event.target as Node)) closePeopleSearch()
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})
onUnmounted(() => {
  if (peopleSearchTimer) clearTimeout(peopleSearchTimer)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})

async function addPerson(person: AdminPerson) {
  peopleBusy.value = true
  try {
    await adminApi.addPersonToPhoto(props.photoId, { personId: person.id })
    if (!people.value.some((p) => p.id === person.id)) {
      people.value = [
        ...people.value,
        {
          id: person.id,
          name: person.name,
          avatarCropPath: person.avatarCropPath ?? null,
        },
      ]
    }
    clearPeopleSearch()
    closePeopleSearch()
  } catch (err) {
    emit(
      'error',
      err instanceof ApiError ? `Falha ao adicionar pessoa: ${err.message}` : 'Falha ao adicionar pessoa.',
    )
  } finally {
    peopleBusy.value = false
  }
}

async function createAndAddPerson() {
  const name = peopleQuery.value.trim()
  if (name === '') {
    return
  }

  peopleBusy.value = true
  try {
    const face = await adminApi.addPersonToPhoto(props.photoId, { name })
    const personId = face.personId
    if (!personId) {
      throw new Error('missing personId')
    }
    if (!people.value.some((p) => p.id === personId)) {
      people.value = [
        ...people.value,
        {
          id: personId,
          name,
          avatarCropPath: null,
        },
      ]
    }
    clearPeopleSearch()
    closePeopleSearch()
  } catch (err) {
    emit(
      'error',
      err instanceof ApiError
        ? `Falha ao criar/adicionar pessoa: ${err.message}`
        : 'Falha ao criar/adicionar pessoa.',
    )
  } finally {
    peopleBusy.value = false
  }
}

async function removePerson(personId: string) {
  peopleBusy.value = true
  try {
    await adminApi.removePersonFromPhoto(props.photoId, personId)
    people.value = people.value.filter((p) => p.id !== personId)
  } catch (err) {
    emit(
      'error',
      err instanceof ApiError ? `Falha ao remover pessoa: ${err.message}` : 'Falha ao remover pessoa.',
    )
  } finally {
    peopleBusy.value = false
  }
}

function selectPersonResult(person: AdminPerson) {
  void addPerson(person)
}

function personAvatarSrc(person: PersonSummary): string | null {
  return mediaUrl(person.avatarCropPath)
}
</script>

<template>
  <ul v-if="people.length > 0" class="admin-people-list">
    <li v-for="person in people" :key="person.id" data-testid="photo-person-row">
      <div class="flex min-w-0 items-center gap-3">
        <img
          v-if="personAvatarSrc(person)"
          :src="personAvatarSrc(person)!"
          alt=""
          class="size-10 shrink-0 rounded-md object-cover bg-muted"
          data-testid="photo-person-avatar"
        />
        <div
          v-else
          class="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
          data-testid="photo-person-avatar-empty"
        >
          —
        </div>
        <RouterLink
          :to="{ name: 'admin-person-edit', params: { id: person.id } }"
          class="truncate font-medium text-foreground hover:underline"
        >
          {{ person.name ?? 'Sem nome' }}
        </RouterLink>
      </div>
      <button
        type="button"
        class="admin-action-link admin-action-link--danger"
        :disabled="peopleBusy"
        @click="removePerson(person.id)"
      >
        Remover
      </button>
    </li>
  </ul>
  <p v-else class="text-sm text-muted-foreground">Nenhuma pessoa marcada.</p>

  <div class="grid gap-2" :class="people.length > 0 ? 'border-t border-border pt-4' : undefined">
    <Label for="people-search" class="admin-label-sentence">Adicionar pessoa</Label>
    <div class="flex flex-col gap-2 sm:flex-row">
      <div ref="peopleSearchRoot" class="relative min-w-0 flex-1">
        <Input
          id="people-search"
          v-model="peopleQuery"
          type="search"
          placeholder="Buscar ou criar pelo nome…"
          autocomplete="off"
          data-testid="people-search"
          :disabled="peopleBusy"
          @focus="onPeopleSearchFocus"
          @input="onPeopleSearchInput"
          @keydown.esc="closePeopleSearch"
        />
        <ul
          v-if="peopleSearchOpen && peopleResults.length > 0"
          class="admin-suggestions"
          data-testid="people-suggestions"
        >
          <li v-for="person in peopleResults" :key="person.id">
            <button
              type="button"
              class="admin-suggestion"
              data-testid="people-suggestion"
              :disabled="peopleBusy"
              @click="selectPersonResult(person)"
            >
              {{ person.name }}
            </button>
          </li>
        </ul>
      </div>
      <Button
        type="button"
        variant="secondary"
        :disabled="peopleBusy || peopleQuery.trim() === ''"
        data-testid="people-create-add"
        @click="createAndAddPerson"
      >
        Adicionar / criar
      </Button>
    </div>
    <p v-if="peopleSearchLoading" class="text-xs text-muted-foreground">Buscando…</p>
  </div>
</template>
