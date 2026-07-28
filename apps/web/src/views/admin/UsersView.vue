<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ApiError, adminApi } from '../../api/client'
import type { AdminUser } from '../../api/types'

const users = ref<AdminUser[]>([])
const currentUser = ref<AdminUser | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)

const dialogOpen = ref(false)
const editing = ref<AdminUser | null>(null)
const formEmail = ref('')
const formPassword = ref('')

const isCreate = computed(() => editing.value === null)
const dialogTitle = computed(() => (isCreate.value ? 'Novo usuário' : 'Editar usuário'))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [list, me] = await Promise.all([adminApi.listUsers(), adminApi.me()])
    users.value = list
    currentUser.value = me
  } catch {
    error.value = 'Falha ao carregar usuários.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openCreate() {
  editing.value = null
  formEmail.value = ''
  formPassword.value = ''
  dialogOpen.value = true
}

function openEdit(user: AdminUser) {
  editing.value = user
  formEmail.value = user.email
  formPassword.value = ''
  dialogOpen.value = true
}

function closeDialog() {
  if (saving.value) return
  dialogOpen.value = false
  editing.value = null
  formEmail.value = ''
  formPassword.value = ''
}

async function save() {
  const email = formEmail.value.trim()
  const password = formPassword.value
  if (!email) {
    error.value = 'Usuário é obrigatório.'
    return
  }
  if (isCreate.value && password.length < 6) {
    error.value = 'A senha deve ter pelo menos 6 caracteres.'
    return
  }
  if (!isCreate.value && password !== '' && password.length < 6) {
    error.value = 'A senha deve ter pelo menos 6 caracteres.'
    return
  }

  saving.value = true
  error.value = null
  try {
    if (isCreate.value) {
      const created = await adminApi.createUser({ email, password })
      users.value = [...users.value, created].sort((a, b) => a.email.localeCompare(b.email))
    } else if (editing.value) {
      const payload: { email?: string; password?: string } = { email }
      if (password !== '') {
        payload.password = password
      }
      const updated = await adminApi.updateUser(editing.value.id, payload)
      users.value = users.value
        .map((u) => (u.id === updated.id ? updated : u))
        .sort((a, b) => a.email.localeCompare(b.email))
      if (currentUser.value?.id === updated.id) {
        currentUser.value = updated
      }
    }
    closeDialog()
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      error.value = 'Já existe um administrador com este usuário.'
    } else {
      error.value = isCreate.value ? 'Falha ao criar usuário.' : 'Falha ao atualizar usuário.'
    }
  } finally {
    saving.value = false
  }
}

function canDelete(user: AdminUser): boolean {
  if (!currentUser.value) return false
  if (user.id === currentUser.value.id) return false
  if (users.value.length <= 1) return false
  return true
}

async function removeUser(user: AdminUser) {
  if (!canDelete(user)) {
    error.value = 'Você não pode excluir sua própria conta nem o último administrador.'
    return
  }
  if (!window.confirm(`Excluir administrador “${user.email}”?`)) {
    return
  }

  error.value = null
  try {
    await adminApi.deleteUser(user.id)
    users.value = users.value.filter((u) => u.id !== user.id)
  } catch (err) {
    if (err instanceof ApiError && err.status === 400) {
      error.value = 'Você não pode excluir sua própria conta nem o último administrador.'
    } else {
      error.value = 'Falha ao excluir usuário.'
    }
  }
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex justify-end">
      <Button type="button" size="sm" data-testid="users-new" @click="openCreate">Novo usuário</Button>
    </div>

    <div v-if="loading" class="admin-panel rounded-xl p-12 text-center text-sm text-muted-foreground">
      Carregando usuários…
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="!loading && users.length > 0" class="admin-panel overflow-hidden rounded-xl">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Usuário</TableHead>
            <TableHead class="w-40">Função</TableHead>
            <TableHead class="w-44 text-right">Ações</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="user in users" :key="user.id" data-testid="user-row">
            <TableCell>
              <span class="font-medium">{{ user.email }}</span>
              <span
                v-if="currentUser?.id === user.id"
                class="ml-2 text-xs text-muted-foreground"
              >
                (você)
              </span>
            </TableCell>
            <TableCell class="text-sm text-muted-foreground">Admin</TableCell>
            <TableCell class="text-right">
              <button
                type="button"
                class="admin-action-link"
                data-testid="user-edit"
                @click="openEdit(user)"
              >
                Editar
              </button>
              <span class="admin-action-sep">·</span>
              <button
                type="button"
                class="admin-action-link admin-action-link--danger"
                data-testid="user-delete"
                :disabled="!canDelete(user)"
                @click="removeUser(user)"
              >
                Excluir
              </button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <p v-else-if="!loading" class="text-sm text-muted-foreground">Nenhum usuário administrador ainda.</p>

    <Dialog :open="dialogOpen" @update:open="(open) => { if (!open) closeDialog() }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ dialogTitle }}</DialogTitle>
          <DialogDescription>
            {{
              isCreate
                ? 'Crie uma conta de administrador com acesso à galeria.'
                : 'Atualize o usuário ou defina uma nova senha. Deixe a senha em branco para manter a atual.'
            }}
          </DialogDescription>
        </DialogHeader>

        <form class="grid gap-4 py-2" @submit.prevent="save">
          <div class="grid gap-2">
            <Label for="user-email">Usuário</Label>
            <Input
              id="user-email"
              v-model="formEmail"
              type="text"
              autocomplete="off"
              data-testid="user-email"
              required
            />
          </div>
          <div class="grid gap-2">
            <Label for="user-password">
              Senha
              <span v-if="!isCreate" class="font-normal text-muted-foreground">(opcional)</span>
            </Label>
            <Input
              id="user-password"
              v-model="formPassword"
              type="password"
              autocomplete="new-password"
              data-testid="user-password"
              :required="isCreate"
              minlength="6"
            />
          </div>

          <DialogFooter class="gap-2 sm:gap-2">
            <Button type="button" variant="outline" :disabled="saving" @click="closeDialog">Cancelar</Button>
            <Button type="submit" :disabled="saving" data-testid="user-save">
              {{ isCreate ? 'Criar' : 'Salvar' }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </section>
</template>
