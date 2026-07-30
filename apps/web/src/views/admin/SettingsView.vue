<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ApiError, adminApi } from '../../api/client'
import type { ProcessingSettings, TagDetector } from '../../api/types'

const DETECTOR_OPTIONS: { value: TagDetector; label: string; hint: string }[] = [
  { value: 'ram_plus', label: 'RAM++ (Swin-Large)', hint: 'Mais preciso, mais lento e pesado' },
  { value: 'mobileclip_s0', label: 'MobileCLIP2-S0', hint: 'Mais leve e rápido' },
  { value: 'mobileclip_s1', label: 'MobileCLIP-S1', hint: 'Equilíbrio entre velocidade e precisão' },
]

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)

const facesEnabled = ref(true)
const tagsEnabled = ref(true)
const tagDetector = ref<TagDetector>('ram_plus')

async function load() {
  loading.value = true
  error.value = null
  saved.value = false
  try {
    const settings = await adminApi.getSettings()
    applySettings(settings)
  } catch {
    error.value = 'Falha ao carregar configurações.'
  } finally {
    loading.value = false
  }
}

function applySettings(settings: ProcessingSettings) {
  facesEnabled.value = settings.facesEnabled
  tagsEnabled.value = settings.tagsEnabled
  tagDetector.value = settings.tagDetector
}

async function save() {
  saving.value = true
  error.value = null
  saved.value = false
  try {
    const updated = await adminApi.updateSettings({
      facesEnabled: facesEnabled.value,
      tagsEnabled: tagsEnabled.value,
      tagDetector: tagDetector.value,
    })
    applySettings(updated)
    saved.value = true
  } catch (err) {
    if (err instanceof ApiError && err.status === 400) {
      error.value = err.message || 'Configuração inválida.'
    } else {
      error.value = 'Falha ao salvar configurações.'
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
    <Alert v-if="error" variant="destructive" data-testid="settings-error">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>
    <Alert v-else-if="saved" data-testid="settings-saved">
      <AlertDescription>Configurações salvas. Jobs ainda na fila usam os valores atuais.</AlertDescription>
    </Alert>

    <Card>
      <CardHeader>
        <CardTitle>Processamento de IA</CardTitle>
        <CardDescription>
          Escolha o detector de tags e ative ou desative rostos e tags. Mudanças
          valem para novos processamentos e para jobs ainda pendentes na fila.
          Fotos já concluídas não são alteradas automaticamente.
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-6">
        <div v-if="loading" class="text-sm text-muted-foreground" data-testid="settings-loading">
          Carregando…
        </div>
        <template v-else>
          <div class="flex flex-col gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium">
              <Checkbox
                :model-value="facesEnabled"
                data-testid="settings-faces-enabled"
                @update:model-value="facesEnabled = $event === true"
              />
              Detectar rostos
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium">
              <Checkbox
                :model-value="tagsEnabled"
                data-testid="settings-tags-enabled"
                @update:model-value="tagsEnabled = $event === true"
              />
              Sugerir tags
            </label>
          </div>

          <div class="flex flex-col gap-2">
            <Label for="tag-detector">Detector de tags</Label>
            <Select
              :model-value="tagDetector"
              :disabled="!tagsEnabled || saving"
              @update:model-value="(v) => (tagDetector = v as TagDetector)"
            >
              <SelectTrigger id="tag-detector" class="w-full" data-testid="settings-tag-detector">
                <SelectValue placeholder="Selecione o detector" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in DETECTOR_OPTIONS"
                  :key="option.value"
                  :value="option.value"
                  :data-testid="`detector-${option.value}`"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
              {{ DETECTOR_OPTIONS.find((o) => o.value === tagDetector)?.hint }}
            </p>
            <p class="text-xs text-muted-foreground">
              MobileCLIP2-S0 / MobileCLIP-S1 usam o vocabulário inglês do RAM++
              (≈4.585 tags). Tags cadastradas com o mesmo slug reutilizam o nome
              traduzido. A primeira execução de um detector MobileCLIP monta um
              cache de embeddings textuais (pode demorar alguns minutos em CPU).
            </p>
          </div>

          <div class="flex justify-end">
            <Button type="button" :disabled="saving" data-testid="settings-save" @click="save">
              {{ saving ? 'Salvando…' : 'Salvar' }}
            </Button>
          </div>
        </template>
      </CardContent>
    </Card>
  </div>
</template>
