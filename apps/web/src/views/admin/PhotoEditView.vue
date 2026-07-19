<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, AdminPhotoDetail, Location, Tag } from '../../api/types'
import LocationMap from '../../components/LocationMap.vue'

const props = defineProps<{ id: string }>()

const photo = ref<AdminPhotoDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)
const saved = ref(false)

const form = reactive<{ title: string; takenAt: string }>({ title: '', takenAt: '' })
const selectedTags = ref<Tag[]>([])
const selectedLocation = ref<Location | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    photo.value = await adminApi.getPhoto(props.id)
    form.title = photo.value.title ?? ''
    form.takenAt = photo.value.takenAt ? photo.value.takenAt.slice(0, 16) : ''
    selectedTags.value = [...photo.value.tags]
    selectedLocation.value = photo.value.location
  } catch {
    error.value = 'Failed to load this photo.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.id, load)

const fullSrc = computed(() => mediaUrl(photo.value?.avifPath))

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    photo.value = await adminApi.updatePhoto(props.id, {
      title: form.title.trim() === '' ? null : form.title.trim(),
      takenAt: form.takenAt === '' ? null : new Date(form.takenAt).toISOString(),
      tagIds: selectedTags.value.map((t) => t.id),
      locationId: selectedLocation.value?.id ?? null,
    })
    saved.value = true
  } catch (err) {
    error.value = err instanceof ApiError ? `Save failed: ${err.message}` : 'Save failed.'
  } finally {
    saving.value = false
  }
}

// --- Tags --------------------------------------------------------------

const tagQuery = ref('')
const tagResults = ref<Tag[]>([])

async function searchTags() {
  tagResults.value = await adminApi.searchTags(tagQuery.value || undefined)
}

function addTag(tag: Tag) {
  if (!selectedTags.value.some((t) => t.id === tag.id)) {
    selectedTags.value.push(tag)
  }
  tagQuery.value = ''
  tagResults.value = []
}

async function createAndAddTag() {
  const name = tagQuery.value.trim()
  if (name === '') {
    return
  }
  try {
    const tag = await adminApi.createTag(name)
    addTag(tag)
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to create tag: ${err.message}` : 'Failed to create tag.'
  }
}

function removeTag(tag: Tag) {
  selectedTags.value = selectedTags.value.filter((t) => t.id !== tag.id)
}

// --- Location ------------------------------------------------------------

const locationQuery = ref('')
const locationResults = ref<Location[]>([])
const hasCoordinates = computed(
  () => selectedLocation.value?.latitude != null && selectedLocation.value?.longitude != null,
)

async function searchLocations() {
  locationResults.value = await adminApi.searchLocations(locationQuery.value || undefined)
}

function chooseLocation(location: Location) {
  selectedLocation.value = location
  locationQuery.value = ''
  locationResults.value = []
}

function clearLocation() {
  selectedLocation.value = null
}

const newLocation = reactive({ name: '', city: '', country: '', latitude: '', longitude: '' })
const showNewLocationForm = ref(false)

async function createLocation() {
  if (newLocation.name.trim() === '') {
    return
  }
  try {
    const location = await adminApi.createLocation({
      name: newLocation.name.trim(),
      city: newLocation.city.trim() === '' ? null : newLocation.city.trim(),
      country: newLocation.country.trim() === '' ? null : newLocation.country.trim(),
      latitude: newLocation.latitude === '' ? null : Number(newLocation.latitude),
      longitude: newLocation.longitude === '' ? null : Number(newLocation.longitude),
    })
    chooseLocation(location)
    showNewLocationForm.value = false
    newLocation.name = ''
    newLocation.city = ''
    newLocation.country = ''
    newLocation.latitude = ''
    newLocation.longitude = ''
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to create location: ${err.message}` : 'Failed to create location.'
  }
}

// --- People --------------------------------------------------------------

const peopleQuery = ref('')
const peopleResults = ref<AdminPerson[]>([])
const peopleBusy = ref(false)

async function searchPeople() {
  peopleResults.value = await adminApi.searchPeople(peopleQuery.value || undefined)
}

async function addPerson(person: AdminPerson) {
  if (!photo.value) {
    return
  }
  peopleBusy.value = true
  try {
    await adminApi.addPersonToPhoto(photo.value.id, person.id)
    if (!photo.value.people.some((p) => p.id === person.id)) {
      photo.value.people.push({ id: person.id, name: person.name })
    }
    peopleQuery.value = ''
    peopleResults.value = []
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to add person: ${err.message}` : 'Failed to add person.'
  } finally {
    peopleBusy.value = false
  }
}

async function removePerson(personId: string) {
  if (!photo.value) {
    return
  }
  peopleBusy.value = true
  try {
    await adminApi.removePersonFromPhoto(photo.value.id, personId)
    photo.value.people = photo.value.people.filter((p) => p.id !== personId)
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to remove person: ${err.message}` : 'Failed to remove person.'
  } finally {
    peopleBusy.value = false
  }
}
</script>

<template>
  <section class="photo-edit">
    <header class="photo-edit__header">
      <h1>Edit photo</h1>
      <RouterLink v-if="photo" :to="{ name: 'admin-album-photos', params: { albumId: photo.albumId } }">
        ← Back to album photos
      </RouterLink>
    </header>

    <p v-if="loading">Loading photo…</p>
    <p v-else-if="!photo" class="photo-edit__error">Photo not found.</p>

    <template v-else>
      <p v-if="error" class="photo-edit__error">{{ error }}</p>
      <p v-if="saved" class="photo-edit__saved">Saved.</p>

      <div class="photo-edit__layout">
        <figure class="photo-edit__preview">
          <img v-if="fullSrc" :src="fullSrc" :alt="photo.title ?? 'Photo'" />
        </figure>

        <form class="photo-edit__form" @submit.prevent="save">
          <label>
            <span>Title</span>
            <input v-model="form.title" />
          </label>
          <label>
            <span>Taken at</span>
            <input v-model="form.takenAt" type="datetime-local" />
          </label>

          <fieldset>
            <legend>Tags</legend>
            <div class="chip-list">
              <span v-for="tag in selectedTags" :key="tag.id" class="chip">
                #{{ tag.name }}
                <button type="button" class="chip__remove" @click="removeTag(tag)">×</button>
              </span>
              <span v-if="selectedTags.length === 0" class="photo-edit__muted">No tags yet.</span>
            </div>
            <div class="search-row">
              <input v-model="tagQuery" placeholder="Search or create a tag…" @input="searchTags" />
              <button type="button" @click="createAndAddTag">Add / create</button>
            </div>
            <ul v-if="tagResults.length > 0" class="search-results">
              <li v-for="tag in tagResults" :key="tag.id">
                <button type="button" @click="addTag(tag)">#{{ tag.name }}</button>
              </li>
            </ul>
          </fieldset>

          <fieldset>
            <legend>Location</legend>
            <p v-if="selectedLocation" class="location-current">
              📍 {{ [selectedLocation.name, selectedLocation.city, selectedLocation.country].filter(Boolean).join(', ') }}
              <button type="button" @click="clearLocation">Clear</button>
            </p>
            <div class="search-row">
              <input v-model="locationQuery" placeholder="Search locations…" @input="searchLocations" />
              <button type="button" @click="showNewLocationForm = !showNewLocationForm">New location</button>
            </div>
            <ul v-if="locationResults.length > 0" class="search-results">
              <li v-for="location in locationResults" :key="location.id">
                <button type="button" @click="chooseLocation(location)">
                  {{ [location.name, location.city].filter(Boolean).join(', ') }}
                </button>
              </li>
            </ul>

            <div v-if="showNewLocationForm" class="new-location">
              <input v-model="newLocation.name" placeholder="Name" />
              <input v-model="newLocation.city" placeholder="City" />
              <input v-model="newLocation.country" placeholder="Country" />
              <input v-model="newLocation.latitude" placeholder="Latitude" />
              <input v-model="newLocation.longitude" placeholder="Longitude" />
              <button type="button" @click="createLocation">Save location</button>
            </div>

            <LocationMap
              v-if="hasCoordinates"
              :latitude="selectedLocation!.latitude!"
              :longitude="selectedLocation!.longitude!"
              :label="selectedLocation!.name"
            />
          </fieldset>

          <button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
        </form>
      </div>

      <fieldset class="people-section">
        <legend>People</legend>
        <ul class="people-list">
          <li v-for="person in photo.people" :key="person.id">
            {{ person.name ?? 'Unnamed' }}
            <button type="button" :disabled="peopleBusy" @click="removePerson(person.id)">Remove</button>
          </li>
          <li v-if="photo.people.length === 0" class="photo-edit__muted">No people tagged.</li>
        </ul>
        <div class="search-row">
          <input v-model="peopleQuery" placeholder="Search named people…" @input="searchPeople" />
        </div>
        <ul v-if="peopleResults.length > 0" class="search-results">
          <li v-for="person in peopleResults" :key="person.id">
            <button type="button" :disabled="peopleBusy" @click="addPerson(person)">{{ person.name }}</button>
          </li>
        </ul>
      </fieldset>
    </template>
  </section>
</template>

<style scoped>
.photo-edit__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.photo-edit__error {
  color: #f87171;
}

.photo-edit__saved {
  color: #4ade80;
}

.photo-edit__muted {
  color: var(--muted, #888);
  font-size: 0.85rem;
}

.photo-edit__layout {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(280px, 1fr);
  gap: 2rem;
  margin-top: 1rem;
}

.photo-edit__preview img {
  width: 100%;
  border-radius: 8px;
  display: block;
}

.photo-edit__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.photo-edit__form label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.85rem;
}

.photo-edit__form input,
fieldset input {
  padding: 0.45rem 0.6rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #111;
  color: inherit;
  font: inherit;
}

fieldset {
  border: 1px solid #262626;
  border-radius: 8px;
  padding: 0.8rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  background: #1a1a1a;
  font-size: 0.8rem;
}

.chip__remove {
  border: none;
  background: none;
  color: inherit;
  cursor: pointer;
  padding: 0;
  font-size: 0.9rem;
  line-height: 1;
}

.search-row {
  display: flex;
  gap: 0.4rem;
}

.search-row input {
  flex: 1;
}

.search-results {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.location-current {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.9rem;
  margin: 0;
}

.new-location {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.4rem;
}

.people-section {
  margin-top: 2rem;
  max-width: 480px;
}

.people-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.people-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.9rem;
}

button {
  padding: 0.35rem 0.7rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #1a1a1a;
  color: inherit;
  cursor: pointer;
  font-size: 0.8rem;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

button[type='submit'] {
  align-self: flex-start;
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
  font-weight: 600;
}
</style>
