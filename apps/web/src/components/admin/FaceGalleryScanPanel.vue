<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
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
import { adminApi, ApiError, mediaUrl } from '../../api/client'
import type { FaceGalleryScan, FaceGalleryScanDetail, FaceGalleryScanMatch, FaceGalleryScanStatus } from '../../api/types'

const props = withDefaults(
  defineProps<{
    showUpload?: boolean
    attachPersonId?: string | null
  }>(),
  { showUpload: true, attachPersonId: null },
)

const emit = defineEmits<{
  attached: [personId: string]
}>()

const router = useRouter()

const POLL_MS = 3000

const scans = ref<FaceGalleryScan[]>([])
const selectedId = ref<string | null>(null)
const selectedDetail = ref<FaceGalleryScanDetail | null>(null)
const loading = ref(true)
const uploadLoading = ref(false)
const detailLoading = ref(false)
const error = ref<string | null>(null)
const confirmName = ref('')
const confirming = ref(false)
const cancellingId = ref<string | null>(null)
const deletingId = ref<string | null>(null)

let pollTimer: ReturnType<typeof setInterval> | null = null

const STATUS_LABEL: Record<FaceGalleryScanStatus, string> = {
  pending: 'Pendente',
  running: 'Em andamento',
  done: 'Concluída',
  failed: 'Falha',
  cancelled: 'Cancelada',
}

const hasActiveScans = computed(() =>
  scans.value.some((s) => s.status === 'pending' || s.status === 'running'),
)

function statusVariant(status: FaceGalleryScanStatus) {
  if (status === 'done') return 'secondary'
  if (status === 'failed') return 'destructive'
  if (status === 'cancelled') return 'outline'
  if (status === 'running') return 'default'
  return 'outline'
}

function progressPercent(scan: FaceGalleryScan): number {
  if (scan.totalPhotos === 0) return 0
  return Math.min(100, Math.round((scan.processedPhotos / scan.totalPhotos) * 100))
}

function isRunning(scan: FaceGalleryScan): boolean {
  return scan.status === 'pending' || scan.status === 'running'
}

function formatWhen(iso: string): string {
  return new Date(iso).toLocaleString('pt-BR', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function stopPoll() {
  if (pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function startPoll() {
  stopPoll()
  pollTimer = setInterval(() => {
    void refreshList()
    if (selectedId.value) void refreshDetail()
  }, POLL_MS)
}

async function refreshList() {
  try {
    const result = await adminApi.listFaceScans({
      perPage: 30,
      targetPersonId: props.attachPersonId ?? undefined,
    })
    scans.value = result.data
    if (selectedId.value) {
      const row = scans.value.find((s) => s.id === selectedId.value)
      if (row && selectedDetail.value) {
        selectedDetail.value = { ...selectedDetail.value, ...row, matches: selectedDetail.value.matches }
      }
    }
    if (hasActiveScans.value) {
      if (!pollTimer) startPoll()
    } else {
      stopPoll()
    }
  } catch {
    // keep last good list during poll
  }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    await refreshList()
    const active = scans.value.find((s) => isRunning(s))
    if (active) {
      await selectScan(active)
    }
    if (hasActiveScans.value) startPoll()
  } catch {
    error.value = 'Falha ao carregar varreduras.'
  } finally {
    loading.value = false
  }
}

async function refreshDetail() {
  if (!selectedId.value) return
  try {
    selectedDetail.value = await adminApi.getFaceScan(selectedId.value)
    if (selectedDetail.value.status === 'cancelled' || selectedDetail.value.status === 'failed') {
      // keep detail open for review
    }
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível carregar os resultados.'
  }
}

async function selectScan(scan: FaceGalleryScan) {
  selectedId.value = scan.id
  detailLoading.value = true
  error.value = null
  try {
    selectedDetail.value = await adminApi.getFaceScan(scan.id)
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível carregar os resultados.'
    selectedDetail.value = null
  } finally {
    detailLoading.value = false
  }
}

async function onUpload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  uploadLoading.value = true
  error.value = null
  try {
    const created = await adminApi.createFaceScan(file)
    await refreshList()
    startPoll()
    await selectScan(created)
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível iniciar a varredura.'
  } finally {
    uploadLoading.value = false
  }
}

function isRemovable(scan: FaceGalleryScan): boolean {
  return scan.status === 'done' || scan.status === 'cancelled' || scan.status === 'failed'
}

async function removeScan(scan: FaceGalleryScan) {
  if (!window.confirm('Remover esta varredura da lista?')) {
    return
  }
  deletingId.value = scan.id
  error.value = null
  try {
    await adminApi.deleteFaceScan(scan.id)
    if (selectedId.value === scan.id) {
      selectedId.value = null
      selectedDetail.value = null
    }
    await refreshList()
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível remover a varredura.'
  } finally {
    deletingId.value = null
  }
}

async function cancelScan(scan: FaceGalleryScan) {
  cancellingId.value = scan.id
  error.value = null
  try {
    await adminApi.cancelFaceScan(scan.id)
    if (selectedId.value === scan.id) {
      selectedId.value = null
      selectedDetail.value = null
    }
    await refreshList()
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível cancelar a varredura.'
  } finally {
    cancellingId.value = null
  }
}

async function toggleMatch(match: FaceGalleryScanMatch) {
  if (!selectedDetail.value) return
  try {
    await adminApi.patchFaceScanMatch(selectedDetail.value.id, match.id, !match.selected)
    match.selected = !match.selected
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível atualizar a seleção.'
  }
}

async function startFromPerson() {
  if (!props.attachPersonId) return
  uploadLoading.value = true
  error.value = null
  try {
    const created = await adminApi.createFaceScanFromPerson(props.attachPersonId)
    await refreshList()
    startPoll()
    await selectScan(created)
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível iniciar a varredura.'
  } finally {
    uploadLoading.value = false
  }
}

async function confirmScan() {
  if (!selectedDetail.value) return
  const name = confirmName.value.trim()
  if (!props.attachPersonId && !name) {
    error.value = 'Informe um nome para a nova pessoa.'
    return
  }
  confirming.value = true
  error.value = null
  try {
    const result = await adminApi.confirmFaceScan(
      selectedDetail.value.id,
      props.attachPersonId ? undefined : name,
    )
    selectedId.value = null
    selectedDetail.value = null
    confirmName.value = ''
    await refreshList()
    if (props.attachPersonId) {
      emit('attached', result.personId)
    } else {
      await router.push({ name: 'admin-person-edit', params: { id: result.personId } })
    }
  } catch (err) {
    error.value =
      err instanceof ApiError ? err.message : 'Não foi possível confirmar a varredura.'
  } finally {
    confirming.value = false
  }
}

onMounted(() => {
  void load()
})

onUnmounted(stopPoll)
</script>

<template>
  <div class="admin-panel space-y-4 rounded-xl p-4" data-testid="gallery-scan-panel">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-sm font-medium">Varreduras por rosto</h2>
        <p class="text-sm text-muted-foreground">
          Compare um recorte de referência com todas as fotos convertidas (AVIF) da galeria.
        </p>
      </div>
      <Button
        v-if="!showUpload"
        as-child
        variant="outline"
        size="sm"
      >
        <RouterLink :to="{ name: 'admin-people' }">Nova varredura em Pessoas</RouterLink>
      </Button>
    </div>

    <div v-if="showUpload" class="space-y-2">
      <Input
        type="file"
        accept="image/jpeg,image/png,image/webp"
        data-testid="gallery-scan-input"
        :disabled="uploadLoading"
        @change="onUpload"
      />
      <p v-if="uploadLoading" class="text-sm text-muted-foreground">Iniciando varredura…</p>
    </div>
    <div v-else-if="attachPersonId" class="space-y-2">
      <Button
        type="button"
        size="sm"
        :disabled="uploadLoading"
        data-testid="gallery-scan-from-person"
        @click="startFromPerson"
      >
        {{ uploadLoading ? 'Iniciando…' : 'Varrer galeria' }}
      </Button>
      <p class="text-xs text-muted-foreground">
        Compara o rosto desta pessoa com todas as fotos convertidas.
      </p>
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <p v-if="loading" class="text-sm text-muted-foreground">Carregando varreduras…</p>

    <div v-else-if="scans.length === 0" class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
      Nenhuma varredura ainda.
      <span v-if="!showUpload"> Crie uma em Pessoas.</span>
    </div>

    <div v-else class="overflow-hidden rounded-lg border border-border">
      <Table data-testid="gallery-scan-list">
        <TableHeader>
          <TableRow>
            <TableHead class="w-20">Referência</TableHead>
            <TableHead>Início</TableHead>
            <TableHead>Progresso</TableHead>
            <TableHead class="w-28">Status</TableHead>
            <TableHead class="w-40 text-right">Ações</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="scan in scans"
            :key="scan.id"
            data-testid="gallery-scan-row"
            :class="selectedId === scan.id ? 'bg-muted/50' : undefined"
          >
            <TableCell>
              <img
                v-if="mediaUrl(scan.referenceCropPath)"
                :src="mediaUrl(scan.referenceCropPath)!"
                alt="Referência"
                class="size-14 rounded-md object-cover bg-muted"
                data-testid="gallery-scan-reference"
              />
              <div
                v-else
                class="flex size-14 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
              >
                —
              </div>
            </TableCell>
            <TableCell class="text-sm text-muted-foreground whitespace-nowrap">
              {{ formatWhen(scan.createdAt) }}
            </TableCell>
            <TableCell>
              <p class="text-sm tabular-nums">
                {{ scan.processedPhotos }} / {{ scan.totalPhotos }}
                <span class="text-muted-foreground">· {{ scan.matchedPhotos }} match</span>
              </p>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                  class="h-full bg-primary transition-all"
                  :style="{ width: `${progressPercent(scan)}%` }"
                />
              </div>
            </TableCell>
            <TableCell>
              <Badge :variant="statusVariant(scan.status)">
                {{ STATUS_LABEL[scan.status] }}
              </Badge>
            </TableCell>
            <TableCell class="text-right">
              <div class="flex justify-end gap-2">
                <Button
                  v-if="isRunning(scan)"
                  type="button"
                  variant="outline"
                  size="sm"
                  data-testid="gallery-scan-watch"
                  @click="selectScan(scan)"
                >
                  Acompanhar
                </Button>
                <Button
                  v-if="isRunning(scan)"
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="cancellingId === scan.id"
                  data-testid="gallery-scan-cancel"
                  @click="cancelScan(scan)"
                >
                  {{ cancellingId === scan.id ? '…' : 'Cancelar' }}
                </Button>
                <Button
                  v-if="scan.status === 'done'"
                  type="button"
                  variant="outline"
                  size="sm"
                  data-testid="gallery-scan-open"
                  @click="selectScan(scan)"
                >
                  Resultados
                </Button>
                <Button
                  v-else-if="!isRunning(scan) && scan.status !== 'done'"
                  type="button"
                  variant="ghost"
                  size="sm"
                  @click="selectScan(scan)"
                >
                  Detalhes
                </Button>
                <Button
                  v-if="isRemovable(scan)"
                  type="button"
                  variant="ghost"
                  size="sm"
                  class="text-destructive hover:text-destructive"
                  :disabled="deletingId === scan.id"
                  data-testid="gallery-scan-delete"
                  @click="removeScan(scan)"
                >
                  {{ deletingId === scan.id ? '…' : 'Remover' }}
                </Button>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <div
      v-if="selectedId"
      class="space-y-4 rounded-lg border border-border p-4"
      data-testid="gallery-scan-detail"
    >
      <p v-if="detailLoading" class="text-sm text-muted-foreground">Carregando resultados…</p>

      <template v-else-if="selectedDetail">
        <p v-if="isRunning(selectedDetail)" class="text-sm font-medium">
          Varredura em andamento — atualiza automaticamente.
        </p>

        <div
          v-if="selectedDetail.matches.length > 0"
          class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
          data-testid="gallery-scan-matches"
        >
          <label
            v-for="match in selectedDetail.matches"
            :key="match.id"
            class="flex cursor-pointer gap-3 rounded-lg border border-border p-3"
          >
            <input
              type="checkbox"
              class="mt-1"
              :checked="match.selected"
              :disabled="selectedDetail.status !== 'done'"
              @change="toggleMatch(match)"
            />
            <img
              v-if="mediaUrl(match.cropPath)"
              :src="mediaUrl(match.cropPath)!"
              alt=""
              class="size-16 rounded-md object-cover bg-muted"
            />
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">
                {{ match.photoTitle || match.photoFilename }}
              </p>
              <p class="text-xs text-muted-foreground">distância {{ match.distance.toFixed(3) }}</p>
            </div>
          </label>
        </div>

        <p
          v-else-if="selectedDetail.status === 'done'"
          class="text-sm text-muted-foreground"
        >
          Nenhuma correspondência encontrada.
        </p>

        <form
          v-if="selectedDetail.status === 'done' && selectedDetail.matchedPhotos > 0"
          class="flex flex-wrap items-end gap-2"
          data-testid="gallery-scan-confirm"
          @submit.prevent="confirmScan"
        >
          <div v-if="!attachPersonId" class="min-w-[12rem] flex-1">
            <Input
              v-model="confirmName"
              type="text"
              placeholder="Nome da nova pessoa"
              data-testid="gallery-scan-name"
            />
          </div>
          <Button type="submit" :disabled="confirming" data-testid="gallery-scan-confirm-button">
            {{ confirming ? 'Salvando…' : attachPersonId ? 'Anexar à pessoa' : 'Criar pessoa' }}
          </Button>
        </form>
      </template>
    </div>
  </div>
</template>
