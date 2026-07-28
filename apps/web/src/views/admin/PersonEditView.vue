<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
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
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, AdminPersonDetail } from '../../api/types'

const props = defineProps<{ id: string }>()
const router = useRouter()

const person = ref<AdminPersonDetail | null>(null)
const namedPeople = ref<AdminPerson[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const deleteOpen = ref(false)

const form = reactive({
  name: '',
  mergeTargetId: '',
})

const title = computed(() => {
  if (!person.value) return 'Pessoa'
  if (person.value.isNamed && person.value.name) return person.value.name
  return 'Agrupamento sem nome'
})

async function load() {
  loading.value = true
  error.value = null
  try {
    const [detail, named] = await Promise.all([
      adminApi.getPerson(props.id),
      adminApi.searchPeople(),
    ])
    person.value = detail
    form.name = detail.name ?? ''
    namedPeople.value = named.filter((p) => p.id !== props.id)
  } catch {
    error.value = 'Falha ao carregar pessoa.'
    person.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)

function faceSrc(cropPath: string | null): string | null {
  return mediaUrl(cropPath)
}

async function saveName() {
  if (!person.value) return
  const name = form.name.trim()
  saving.value = true
  error.value = null
  try {
    person.value = await adminApi.updatePerson(person.value.id, {
      name: name === '' ? null : name,
    })
    form.name = person.value.name ?? ''
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao salvar: ${err.message}` : 'Falha ao salvar nome.'
  } finally {
    saving.value = false
  }
}

async function setPrimaryFace(faceId: string) {
  if (!person.value) return
  saving.value = true
  error.value = null
  try {
    person.value = await adminApi.updatePerson(person.value.id, { avatarFaceId: faceId })
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao definir rosto principal: ${err.message}` : 'Falha ao definir rosto principal.'
  } finally {
    saving.value = false
  }
}

async function clearPrimaryFace() {
  if (!person.value) return
  saving.value = true
  error.value = null
  try {
    person.value = await adminApi.updatePerson(person.value.id, { avatarFaceId: null })
  } catch (err) {
    error.value =
      err instanceof ApiError ? `Falha ao remover rosto principal: ${err.message}` : 'Falha ao remover rosto principal.'
  } finally {
    saving.value = false
  }
}

async function mergeInto() {
  if (!person.value || !form.mergeTargetId) {
    error.value = 'Escolha uma pessoa para mesclar.'
    return
  }
  saving.value = true
  error.value = null
  try {
    await adminApi.mergePerson(person.value.id, form.mergeTargetId)
    await router.push({ name: 'admin-person-edit', params: { id: form.mergeTargetId } })
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao mesclar: ${err.message}` : 'Falha ao mesclar pessoa.'
  } finally {
    saving.value = false
  }
}

async function deletePerson() {
  if (!person.value) return
  saving.value = true
  error.value = null
  try {
    await adminApi.discardPerson(person.value.id)
    deleteOpen.value = false
    await router.push({ name: 'admin-people' })
  } catch (err) {
    error.value = err instanceof ApiError ? `Falha ao excluir: ${err.message}` : 'Falha ao excluir pessoa.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="space-y-6">
    <div>
      <RouterLink to="/admin/people" class="admin-back-link">← Pessoas</RouterLink>
    </div>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Carregando pessoa…
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <template v-if="!loading && person">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="font-display text-2xl font-semibold text-foreground">{{ title }}</h2>
          <div class="mt-2 flex items-center gap-2">
            <Badge :variant="person.isNamed ? 'default' : 'secondary'">
              {{ person.isNamed ? 'Nomeada' : 'Sem nome' }}
            </Badge>
            <span class="text-sm text-muted-foreground">{{ person.faceCount }} rosto(s)</span>
          </div>
        </div>
        <Button
          type="button"
          variant="destructive"
          size="sm"
          data-testid="delete-open"
          :disabled="saving"
          @click="deleteOpen = true"
        >
          Excluir pessoa
        </Button>
      </div>

      <div class="admin-panel space-y-4 rounded-xl p-6">
        <div class="space-y-2">
          <Label for="person-name">Nome</Label>
          <div class="flex gap-2">
            <Input
              id="person-name"
              v-model="form.name"
              type="text"
              placeholder="Nome da pessoa"
              :disabled="saving"
              data-testid="person-name-input"
              class="max-w-md flex-1"
            />
            <Button type="button" size="sm" :disabled="saving" data-testid="save-name" @click="saveName">
              Salvar nome
            </Button>
          </div>
          <p class="text-xs text-muted-foreground">Deixe em branco e salve para marcar como sem nome.</p>
        </div>

        <div v-if="namedPeople.length > 0" class="space-y-2 border-t border-border/60 pt-4">
          <Label>Mesclar com outra pessoa</Label>
          <div class="flex gap-2">
            <Select
              :model-value="form.mergeTargetId"
              :disabled="saving"
              @update:model-value="(v) => (form.mergeTargetId = String(v ?? ''))"
            >
              <SelectTrigger class="max-w-md min-w-0 flex-1" data-testid="merge-target">
                <SelectValue placeholder="Escolha uma pessoa…" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="p in namedPeople" :key="p.id" :value="p.id">
                  {{ p.name }}
                </SelectItem>
              </SelectContent>
            </Select>
            <Button type="button" variant="outline" size="sm" :disabled="saving" data-testid="merge-submit" @click="mergeInto">
              Mesclar
            </Button>
          </div>
        </div>
      </div>

      <div class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <h3 class="text-sm font-medium text-foreground">Rostos</h3>
          <Button
            v-if="person.avatarFaceId"
            type="button"
            variant="ghost"
            size="sm"
            :disabled="saving"
            data-testid="clear-primary"
            @click="clearPrimaryFace"
          >
            Remover principal
          </Button>
        </div>

        <div
          v-if="person.faces.length === 0"
          class="admin-upload-zone rounded-xl p-10 text-center text-sm text-muted-foreground"
        >
          Nenhum rosto vinculado a esta pessoa.
        </div>

        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
          <button
            v-for="face in person.faces"
            :key="face.id"
            type="button"
            class="group relative overflow-hidden rounded-lg border bg-muted text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :class="
              person.avatarFaceId === face.id
                ? 'border-foreground ring-2 ring-foreground'
                : 'border-transparent hover:border-border'
            "
            data-testid="face-tile"
            :disabled="saving || !face.cropPath"
            @click="setPrimaryFace(face.id)"
          >
            <img
              v-if="faceSrc(face.cropPath)"
              :src="faceSrc(face.cropPath)!"
              alt="Recorte do rosto"
              class="aspect-square w-full object-cover"
            />
            <div v-else class="flex aspect-square items-center justify-center text-xs text-muted-foreground">
              Sem recorte
            </div>
            <span
              v-if="person.avatarFaceId === face.id"
              class="absolute bottom-1.5 left-1.5 rounded bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background"
              data-testid="primary-badge"
            >
              Principal
            </span>
            <span
              v-else
              class="absolute inset-x-0 bottom-0 bg-background/80 py-1 text-center text-[10px] text-muted-foreground opacity-0 transition group-hover:opacity-100"
            >
              Definir como principal
            </span>
          </button>
        </div>
      </div>
    </template>

    <Dialog :open="deleteOpen" @update:open="(open) => { if (!open && !saving) deleteOpen = false }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Excluir esta pessoa?</DialogTitle>
          <DialogDescription>
            Excluir {{ title }} e {{ person?.faceCount ?? 0 }} rosto(s)? Esta ação não pode ser desfeita.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:gap-2">
          <Button type="button" variant="outline" :disabled="saving" data-testid="delete-cancel" @click="deleteOpen = false">
            Cancelar
          </Button>
          <Button
            type="button"
            variant="destructive"
            class="admin-btn-danger-solid"
            :disabled="saving"
            data-testid="delete-confirm"
            @click="deletePerson"
          >
            Excluir pessoa
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
