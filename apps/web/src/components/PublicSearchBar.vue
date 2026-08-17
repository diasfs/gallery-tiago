<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { api, mediaUrl } from '../api/client'
import type { PersonSummary, Tag } from '../api/types'

export type SearchDateMode = 'year' | 'range'

export interface PublicSearchPerson {
  id: string
  name: string
  avatarCropPath?: string | null
}

export interface PublicSearchBarState {
  q: string
  people: PublicSearchPerson[]
  tags: Array<{ id: string; name: string; slug: string }>
  dateMode: SearchDateMode
  year: string
  from: string
  to: string
}

const props = withDefaults(
  defineProps<{
    modelValue?: PublicSearchBarState
    compact?: boolean
  }>(),
  {
    compact: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [PublicSearchBarState]
  submit: [PublicSearchBarState]
}>()

const state = ref<PublicSearchBarState>(
  props.modelValue
    ? {
        ...props.modelValue,
        people: [...props.modelValue.people],
        tags: [...props.modelValue.tags],
      }
    : {
        q: '',
        people: [],
        tags: [],
        dateMode: 'year',
        year: '',
        from: '',
        to: '',
      },
)

watch(
  () => props.modelValue,
  (next) => {
    if (!next) return
    state.value = {
      ...next,
      people: [...next.people],
      tags: [...next.tags],
    }
  },
  { deep: true },
)

function sync() {
  emit('update:modelValue', {
    ...state.value,
    people: [...state.value.people],
    tags: [...state.value.tags],
  })
}

const tagQuery = ref('')
const tagSuggestions = ref<Tag[]>([])
const showTags = ref(false)
let tagTimer: ReturnType<typeof setTimeout> | null = null

const personQuery = ref('')
const personSuggestions = ref<PersonSummary[]>([])
const showPersonSuggest = ref(false)
let personTimer: ReturnType<typeof setTimeout> | null = null

const selectedPersonIds = computed(() => new Set(state.value.people.map((person) => person.id)))
const selectedTagSlugs = computed(() => new Set(state.value.tags.map((t) => t.slug)))

async function loadTags(q: string) {
  try {
    tagSuggestions.value = (await api.searchTags(q || undefined)).filter(
      (t) => !selectedTagSlugs.value.has(t.slug),
    )
  } catch {
    tagSuggestions.value = []
  }
}

function onTagInput() {
  showTags.value = true
  if (tagTimer) clearTimeout(tagTimer)
  tagTimer = setTimeout(() => void loadTags(tagQuery.value.trim()), 200)
}

function addTag(tag: Tag) {
  if (selectedTagSlugs.value.has(tag.slug)) return
  state.value.tags.push({ id: tag.id, name: tag.name, slug: tag.slug })
  tagQuery.value = ''
  tagSuggestions.value = []
  showTags.value = false
  sync()
}

function removeTag(slug: string) {
  state.value.tags = state.value.tags.filter((t) => t.slug !== slug)
  sync()
}

function personAvatar(person: PersonSummary): string | null {
  return mediaUrl(person.avatarCropPath)
}

async function loadPeople(q: string) {
  const trimmed = q.trim()
  if (!trimmed) {
    personSuggestions.value = []
    return
  }
  try {
    personSuggestions.value = (await api.searchPeople(trimmed)).filter(
      (person) => !selectedPersonIds.value.has(person.id),
    )
  } catch {
    personSuggestions.value = []
  }
}

function onPersonInput() {
  const q = personQuery.value.trim()
  if (!q) {
    personSuggestions.value = []
    showPersonSuggest.value = false
    return
  }
  showPersonSuggest.value = true
  if (personTimer) clearTimeout(personTimer)
  personTimer = setTimeout(() => void loadPeople(q), 200)
}

function selectPerson(person: PersonSummary) {
  if (selectedPersonIds.value.has(person.id)) return
  state.value.people.push({
    id: person.id,
    name: person.name ?? '',
    avatarCropPath: person.avatarCropPath,
  })
  personQuery.value = ''
  personSuggestions.value = []
  showPersonSuggest.value = false
  sync()
}

function removePerson(id: string) {
  state.value.people = state.value.people.filter((person) => person.id !== id)
  sync()
}

function setDateMode(mode: SearchDateMode) {
  state.value.dateMode = mode
  if (mode === 'year') {
    state.value.from = ''
    state.value.to = ''
  } else {
    state.value.year = ''
  }
  sync()
}

function clearDate() {
  state.value.year = ''
  state.value.from = ''
  state.value.to = ''
  sync()
}

function submit() {
  sync()
  emit('submit', {
    ...state.value,
    people: [...state.value.people],
    tags: [...state.value.tags],
  })
}

function onDocClick(event: MouseEvent) {
  const target = event.target as HTMLElement | null
  if (!target?.closest('[data-search-tags]')) showTags.value = false
  if (!target?.closest('[data-search-person]')) showPersonSuggest.value = false
}

onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  if (tagTimer) clearTimeout(tagTimer)
  if (personTimer) clearTimeout(personTimer)
})
</script>

<template>
  <form class="search-bar" :class="{ 'search-bar--compact': compact }" @submit.prevent="submit">
    <div class="search-bar__row">
      <input
        v-model="state.q"
        type="search"
        class="search-bar__q"
        placeholder="Buscar álbuns e fotos…"
        data-testid="search-q"
        @input="sync"
      />
      <button type="submit" class="search-bar__submit" data-testid="search-submit">Buscar</button>
    </div>

    <div class="search-bar__filters">
      <div class="search-bar__suggest search-bar__person" data-search-person>
        <input
          v-model="personQuery"
          type="search"
          class="search-bar__filter-input"
          placeholder="Pessoa…"
          autocomplete="off"
          data-testid="search-person-input"
          @input="onPersonInput"
          @keydown.esc="showPersonSuggest = false"
        />
        <ul
          v-if="showPersonSuggest && personSuggestions.length"
          class="search-bar__dropdown"
          data-testid="search-person-suggest"
        >
          <li v-for="person in personSuggestions" :key="person.id">
            <button type="button" class="search-bar__person-option" @click="selectPerson(person)">
              <img
                v-if="personAvatar(person)"
                :src="personAvatar(person)!"
                alt=""
                class="search-bar__person-avatar"
              />
              <span v-else class="search-bar__person-avatar search-bar__person-avatar--empty" aria-hidden="true" />
              <span class="search-bar__person-name">{{ person.name }}</span>
            </button>
          </li>
        </ul>
      </div>

      <div class="search-bar__suggest" data-search-tags>
        <input
          v-model="tagQuery"
          type="text"
          class="search-bar__filter-input"
          placeholder="Adicionar tag…"
          data-testid="search-tag-input"
          @focus="showTags = true; void loadTags(tagQuery.trim())"
          @input="onTagInput"
        />
        <ul v-if="showTags && tagSuggestions.length" class="search-bar__dropdown" data-testid="search-tag-suggest">
          <li v-for="tag in tagSuggestions" :key="tag.id">
            <button type="button" @click="addTag(tag)">{{ tag.name }}</button>
          </li>
        </ul>
      </div>

      <div class="search-bar__date" data-testid="search-date">
        <div class="search-bar__date-modes" role="group" aria-label="Modo de filtro por data">
          <button
            type="button"
            class="search-bar__mode"
            :class="{ 'search-bar__mode--active': state.dateMode === 'year' }"
            :aria-pressed="state.dateMode === 'year'"
            data-testid="search-date-year-mode"
            @click="setDateMode('year')"
          >
            Ano
          </button>
          <button
            type="button"
            class="search-bar__mode"
            :class="{ 'search-bar__mode--active': state.dateMode === 'range' }"
            :aria-pressed="state.dateMode === 'range'"
            data-testid="search-date-range-mode"
            @click="setDateMode('range')"
          >
            Período
          </button>
        </div>
        <div v-if="state.dateMode === 'year'" class="search-bar__date-fields">
          <input
            v-model="state.year"
            type="text"
            inputmode="numeric"
            maxlength="4"
            pattern="[0-9]{4}"
            placeholder="YYYY"
            class="search-bar__year"
            data-testid="search-year"
            aria-label="Ano"
            @input="sync"
          />
        </div>
        <div v-else class="search-bar__date-fields search-bar__date-fields--range">
          <label class="search-bar__date-field">
            <span class="search-bar__date-label">De</span>
            <input
              v-model="state.from"
              type="date"
              class="search-bar__from"
              data-testid="search-from"
              @input="sync"
            />
          </label>
          <label class="search-bar__date-field">
            <span class="search-bar__date-label">Até</span>
            <input
              v-model="state.to"
              type="date"
              class="search-bar__to"
              data-testid="search-to"
              @input="sync"
            />
          </label>
        </div>
        <button
          v-if="state.year || state.from || state.to"
          type="button"
          class="search-bar__clear-date"
          data-testid="search-clear-date"
          @click="clearDate"
        >
          Limpar data
        </button>
      </div>
    </div>

    <div v-if="state.people.length || state.tags.length" class="search-bar__pills" data-testid="search-pills">
      <button
        v-for="person in state.people"
        :key="person.id"
        type="button"
        class="search-bar__pill search-bar__pill--person"
        data-testid="search-person-pill"
        @click="removePerson(person.id)"
      >
        <img
          v-if="personAvatar(person)"
          :src="personAvatar(person)!"
          alt=""
          class="search-bar__pill-avatar"
        />
        <span v-else class="search-bar__pill-avatar search-bar__pill-avatar--empty" aria-hidden="true" />
        <span class="search-bar__pill-label">{{ person.name }}</span>
        <span aria-hidden="true"> ×</span>
      </button>
      <button
        v-for="tag in state.tags"
        :key="tag.slug"
        type="button"
        class="search-bar__pill search-bar__pill--tag"
        data-testid="search-tag-pill"
        @click="removeTag(tag.slug)"
      >
        {{ tag.name }} ×
      </button>
    </div>
  </form>
</template>

<style scoped>
.search-bar {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: 100%;
}

.search-bar__row {
  display: flex;
  gap: 0.5rem;
}

.search-bar__q {
  flex: 1;
  min-width: 0;
  border: 1px solid #d4d4d4;
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
  font: inherit;
  background: #fff;
  color: #1c1917;
}

.search-bar__q::placeholder,
.search-bar__filter-input::placeholder,
.search-bar__year::placeholder {
  color: #78716c;
  opacity: 1;
}

.search-bar__submit {
  border: 0;
  border-radius: 0.5rem;
  padding: 0.5rem 0.9rem;
  font: inherit;
  font-weight: 600;
  background: #1c1917;
  color: #fafaf9;
  cursor: pointer;
}

.search-bar__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: flex-start;
}

.search-bar__person {
  min-width: 10rem;
  flex: 1 1 10rem;
}

.search-bar__person-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.search-bar__person-avatar {
  width: 2rem;
  height: 2rem;
  border-radius: 0.35rem;
  object-fit: cover;
  flex-shrink: 0;
  background: #e7e5e4;
}

.search-bar__person-avatar--empty {
  display: inline-block;
}

.search-bar__person-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-bar__suggest {
  position: relative;
  min-width: 10rem;
  flex: 1 1 10rem;
}

.search-bar__filter-input,
.search-bar__year,
.search-bar__from,
.search-bar__to {
  width: 100%;
  border: 1px solid #d4d4d4;
  border-radius: 0.5rem;
  padding: 0.4rem 0.65rem;
  font: inherit;
  background: #fff;
  color: #1c1917;
  color-scheme: light;
}

.search-bar__year {
  width: 5.5rem;
}

.search-bar__date {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.search-bar__date-modes {
  display: inline-flex;
  gap: 0;
  padding: 0.15rem;
  border-radius: 999px;
  background: rgb(255 255 255 / 0.08);
  border: 1px solid rgb(255 255 255 / 0.18);
}

.search-bar__mode {
  border: 0;
  border-radius: 999px;
  padding: 0.3rem 0.75rem;
  font: inherit;
  font-size: 0.8rem;
  background: transparent;
  color: #a8a29e;
  cursor: pointer;
}

.search-bar__mode--active {
  background: #fafaf9;
  color: #1c1917;
  font-weight: 600;
}

.search-bar__date-fields {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.search-bar__date-fields--range {
  flex-wrap: wrap;
}

.search-bar__date-field {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.search-bar__date-label {
  font-size: 0.75rem;
  color: #a8a29e;
  white-space: nowrap;
}

.search-bar__clear-date {
  border: 0;
  background: transparent;
  color: #a8a29e;
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
  text-decoration: underline;
}

.search-bar__dropdown {
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.25rem);
  left: 0;
  right: 0;
  margin: 0;
  padding: 0.25rem;
  list-style: none;
  border: 1px solid #d4d4d4;
  border-radius: 0.5rem;
  background: #fff;
  box-shadow: 0 8px 24px rgb(28 25 23 / 0.12);
  max-height: 12rem;
  overflow: auto;
}

.search-bar__dropdown button {
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.45rem 0.55rem;
  border-radius: 0.35rem;
  font: inherit;
  color: #1c1917;
  cursor: pointer;
}

.search-bar__dropdown button:hover {
  background: #f5f5f4;
}

.search-bar__pills {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.search-bar__pill {
  border: 0;
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  font: inherit;
  font-size: 0.8rem;
  background: #e7e5e4;
  color: #1c1917;
  cursor: pointer;
}

.search-bar__pill--tag {
  background: #d6d3d1;
}

.search-bar__pill--person {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  max-width: 100%;
}

.search-bar__pill-avatar {
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 0.25rem;
  object-fit: cover;
  flex-shrink: 0;
  background: #d6d3d1;
}

.search-bar__pill-avatar--empty {
  display: inline-block;
}

.search-bar__pill-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-bar--compact .search-bar__filters {
  gap: 0.4rem;
}
</style>
