<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { adminApi, mediaUrl } from '../api/client'
import type { AdminPerson, AdminPersonDetail, PersonMergeCandidate } from '../api/types'
import { useAdminPersonSearch } from '../composables/useAdminPersonSearch'
import { mergePair } from '../lib/personMerge'

const props = defineProps<{ personId: string }>()
const emit = defineEmits<{
  named: [payload: { id: string; name: string | null }]
  merged: [payload: { survivorId: string }]
}>()

const adminPerson = ref<AdminPersonDetail | null>(null)
const nameDraft = ref('')
const saving = ref(false)
const adminError = ref<string | null>(null)

const mergeCandidates = ref<PersonMergeCandidate[]>([])
const mergeCandidatesLoading = ref(false)
const mergeCandidatesError = ref<string | null>(null)
const mergeCandidateBusyId = ref<string | null>(null)

const form = reactive({ mergeTargetId: '' })
const {
  query: mergeQuery,
  results: mergeResults,
  loading: mergeLoading,
  error: mergeSearchError,
  search: searchMergeTargets,
  clear: clearMergeSearch,
} = useAdminPersonSearch(() => props.personId)

let mergeSearchTimer: ReturnType<typeof setTimeout> | null = null
const mergeSearchOpen = ref(false)
const mergeSearchRoot = ref<HTMLElement | null>(null)

const hasEmbeddings = computed(() => adminPerson.value?.faces.some((f) => f.hasEmbedding) ?? false)

function candidateLabel(candidate: PersonMergeCandidate): string {
  if (candidate.isNamed && candidate.name) return candidate.name
  return 'Sem nome'
}

function faceSrc(cropPath: string | null | undefined): string | null {
  return mediaUrl(cropPath)
}

function onMergeSearchInput(event: Event) {
  mergeQuery.value = (event.target as HTMLInputElement).value
  form.mergeTargetId = ''
  mergeSearchOpen.value = true
  if (mergeSearchTimer) clearTimeout(mergeSearchTimer)
  mergeSearchTimer = setTimeout(() => void searchMergeTargets(), 200)
}

function onMergeSearchFocus() {
  mergeSearchOpen.value = true
  if (mergeSearchTimer) clearTimeout(mergeSearchTimer)
  void searchMergeTargets()
}

function closeMergeSearch() {
  mergeSearchOpen.value = false
}

function onDocumentPointerDown(event: PointerEvent) {
  const root = mergeSearchRoot.value
  if (!root || root.contains(event.target as Node)) return
  closeMergeSearch()
}

function selectMergeTarget(candidate: AdminPerson) {
  form.mergeTargetId = candidate.id
  mergeQuery.value = candidate.name ?? ''
  mergeResults.value = []
  closeMergeSearch()
}

async function loadMergeCandidates() {
  if (!adminPerson.value || !hasEmbeddings.value) {
    mergeCandidates.value = []
    mergeCandidatesError.value = null
    return
  }
  mergeCandidatesLoading.value = true
  mergeCandidatesError.value = null
  try {
    mergeCandidates.value = await adminApi.listPersonMergeSuggestions(adminPerson.value.id)
  } catch {
    mergeCandidates.value = []
    mergeCandidatesError.value = 'Falha ao buscar possíveis duplicatas.'
  } finally {
    mergeCandidatesLoading.value = false
  }
}

async function load() {
  adminError.value = null
  form.mergeTargetId = ''
  clearMergeSearch()
  try {
    adminPerson.value = await adminApi.getPerson(props.personId)
    nameDraft.value = adminPerson.value.name ?? ''
    await loadMergeCandidates()
  } catch {
    adminPerson.value = null
    mergeCandidates.value = []
    adminError.value = 'Falha ao carregar controles de admin.'
  }
}

watch(
  () => props.personId,
  () => {
    void load()
  },
  { immediate: true },
)

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  if (mergeSearchTimer) clearTimeout(mergeSearchTimer)
})

async function saveName() {
  if (!adminPerson.value) return
  saving.value = true
  adminError.value = null
  const trimmed = nameDraft.value.trim()
  try {
    const updated = await adminApi.updatePerson(adminPerson.value.id, {
      name: trimmed === '' ? null : trimmed,
    })
    adminPerson.value = { ...adminPerson.value, name: updated.name, isNamed: updated.isNamed }
    nameDraft.value = updated.name ?? ''
    emit('named', { id: updated.id, name: updated.name })
  } catch {
    adminError.value = 'Falha ao salvar nome.'
  } finally {
    saving.value = false
  }
}

async function mergeInto() {
  if (!adminPerson.value || !form.mergeTargetId) {
    adminError.value = 'Escolha uma pessoa para mesclar.'
    return
  }
  saving.value = true
  adminError.value = null
  try {
    await adminApi.mergePerson(adminPerson.value.id, form.mergeTargetId)
    emit('merged', { survivorId: form.mergeTargetId })
  } catch {
    adminError.value = 'Falha ao mesclar pessoa.'
  } finally {
    saving.value = false
  }
}

async function acceptMergeCandidate(candidate: PersonMergeCandidate) {
  if (!adminPerson.value) return
  const { sourceId, targetId } = mergePair(adminPerson.value, candidate)
  mergeCandidateBusyId.value = candidate.personId
  adminError.value = null
  try {
    await adminApi.mergePerson(sourceId, targetId)
    emit('merged', { survivorId: targetId })
  } catch {
    adminError.value = 'Falha ao mesclar pessoa.'
  } finally {
    mergeCandidateBusyId.value = null
  }
}
</script>

<template>
  <div class="person-admin" data-testid="person-admin">
    <p v-if="adminError" class="person-admin__error" data-testid="person-admin-error">
      {{ adminError }}
    </p>

    <div class="person-admin__row">
      <label class="person-admin__label" for="person-name">Nome</label>
      <div class="person-admin__controls">
        <input
          id="person-name"
          v-model="nameDraft"
          type="text"
          class="person-admin__input"
          :disabled="saving"
          data-testid="person-name-input"
          @keydown.enter.prevent="saveName"
        />
        <button
          type="button"
          class="person-admin__btn"
          :disabled="saving"
          data-testid="person-name-save"
          @click="saveName"
        >
          Salvar
        </button>
      </div>
    </div>

    <div class="person-admin__row">
      <label class="person-admin__label" for="person-merge-search">Mesclar</label>
      <div class="person-admin__controls">
        <div ref="mergeSearchRoot" class="person-admin__search">
          <input
            id="person-merge-search"
            v-model="mergeQuery"
            type="search"
            class="person-admin__input"
            placeholder="Buscar pessoa nomeada…"
            :disabled="saving"
            autocomplete="off"
            data-testid="person-merge-search"
            @focus="onMergeSearchFocus"
            @input="onMergeSearchInput"
            @keydown.esc="closeMergeSearch"
          />
          <ul
            v-if="mergeSearchOpen && mergeResults.length > 0"
            class="person-admin__suggestions"
            data-testid="person-merge-suggestions"
          >
            <li v-for="candidate in mergeResults" :key="candidate.id">
              <button
                type="button"
                class="person-admin__suggestion"
                data-testid="person-merge-suggestion"
                @click="selectMergeTarget(candidate)"
              >
                {{ candidate.name }}
              </button>
            </li>
          </ul>
        </div>
        <button
          type="button"
          class="person-admin__btn"
          :disabled="saving || !form.mergeTargetId"
          data-testid="person-merge-submit"
          @click="mergeInto"
        >
          Mesclar
        </button>
      </div>
      <p v-if="mergeLoading" class="person-admin__hint">Buscando…</p>
      <p v-else-if="mergeSearchError" class="person-admin__error">{{ mergeSearchError }}</p>
    </div>

    <div v-if="hasEmbeddings" class="person-admin__dupes" data-testid="person-merge-candidates">
      <h2 class="person-admin__label">Possíveis duplicatas</h2>
      <p v-if="mergeCandidatesLoading" class="person-admin__hint">Buscando…</p>
      <p v-else-if="mergeCandidatesError" class="person-admin__error">{{ mergeCandidatesError }}</p>
      <p
        v-else-if="mergeCandidates.length === 0"
        class="person-admin__hint"
        data-testid="person-merge-candidates-empty"
      >
        Nenhuma pessoa parecida dentro do limiar.
      </p>
      <ul v-else class="person-admin__candidate-list" data-testid="person-merge-candidates-list">
        <li
          v-for="candidate in mergeCandidates"
          :key="candidate.personId"
          class="person-admin__candidate"
          data-testid="person-merge-candidate"
        >
          <img
            v-if="faceSrc(candidate.avatarCropPath)"
            :src="faceSrc(candidate.avatarCropPath)!"
            alt=""
            class="person-admin__avatar"
          />
          <div v-else class="person-admin__avatar person-admin__avatar--empty">—</div>
          <div class="person-admin__candidate-meta">
            <span class="person-admin__candidate-name">{{ candidateLabel(candidate) }}</span>
            <span class="person-admin__hint">
              {{ candidate.faceCount }} rosto(s) · distância {{ candidate.distance.toFixed(3) }}
            </span>
          </div>
          <button
            type="button"
            class="person-admin__btn"
            :disabled="saving || mergeCandidateBusyId === candidate.personId"
            data-testid="person-merge-candidate-accept"
            @click="acceptMergeCandidate(candidate)"
          >
            {{ mergeCandidateBusyId === candidate.personId ? 'Mesclando…' : 'Mesclar' }}
          </button>
        </li>
      </ul>
    </div>
    <p
      v-else-if="adminPerson && !hasEmbeddings"
      class="person-admin__hint"
      data-testid="person-merge-candidates-no-embedding"
    >
      Esta pessoa não tem embedding — não há sugestões de mesclagem.
    </p>
  </div>
</template>

<style scoped>
.person-admin {
  margin: 0.75rem 0 1.5rem;
  padding: 0.85rem 1rem;
  border-radius: 10px;
  background: #1a1a1a;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.person-admin__row {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.person-admin__label {
  margin: 0;
  font-size: 0.8rem;
  color: var(--muted, #888);
  font-weight: 500;
}

.person-admin__controls {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.person-admin__input {
  flex: 1;
  min-width: 12rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid #333;
  border-radius: 8px;
  background: #111;
  color: inherit;
  font: inherit;
}

.person-admin__btn {
  padding: 0.4rem 0.75rem;
  border: 0;
  border-radius: 8px;
  background: #2a2a2a;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.person-admin__btn:hover:not(:disabled) {
  background: #363636;
}

.person-admin__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.person-admin__search {
  position: relative;
  flex: 1;
  min-width: 12rem;
}

.person-admin__suggestions {
  position: absolute;
  z-index: 5;
  left: 0;
  right: 0;
  margin: 0.25rem 0 0;
  padding: 0.25rem;
  list-style: none;
  border-radius: 8px;
  background: #222;
  border: 1px solid #333;
  max-height: 14rem;
  overflow: auto;
}

.person-admin__suggestion {
  display: block;
  width: 100%;
  text-align: left;
  padding: 0.45rem 0.6rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.person-admin__suggestion:hover {
  background: #2e2e2e;
}

.person-admin__hint {
  margin: 0;
  font-size: 0.8rem;
  color: var(--muted, #888);
}

.person-admin__error {
  margin: 0;
  font-size: 0.85rem;
  color: #f87171;
}

.person-admin__dupes {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding-top: 0.35rem;
  border-top: 1px solid #2a2a2a;
}

.person-admin__candidate-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.person-admin__candidate {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
}

.person-admin__avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 8px;
  object-fit: cover;
  background: #111;
  flex-shrink: 0;
}

.person-admin__avatar--empty {
  display: grid;
  place-items: center;
  color: var(--muted, #888);
  font-size: 0.75rem;
}

.person-admin__candidate-meta {
  flex: 1;
  min-width: 8rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.person-admin__candidate-name {
  font-size: 0.9rem;
}
</style>
