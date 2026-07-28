<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, adminApi } from '../../api/client'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function submit() {
  error.value = null
  submitting.value = true
  try {
    await adminApi.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin'
    await router.push(redirect)
  } catch (err) {
    error.value = err instanceof ApiError && err.status === 401 ? 'Usuário ou senha inválidos.' : 'Falha no login.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="admin-root relative flex min-h-screen items-center justify-center overflow-hidden px-4">
    <div class="admin-login-bg pointer-events-none absolute inset-0" aria-hidden="true" />

    <Card class="admin-panel relative w-full max-w-[22rem] rounded-2xl border shadow-none">
      <CardHeader class="space-y-4 pb-0 text-center">
        <div
          class="mx-auto flex size-11 items-center justify-center rounded-xl bg-foreground text-xs font-bold tracking-[0.2em] text-background"
          aria-hidden="true"
        >
          G
        </div>
        <div>
          <CardTitle class="font-display text-2xl font-semibold tracking-tight">Gallery</CardTitle>
          <CardDescription class="mt-2 text-sm">Entre no painel administrativo</CardDescription>
        </div>
      </CardHeader>
      <CardContent class="pt-6">
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div class="space-y-1.5">
            <Label for="email">Usuário</Label>
            <Input id="email" v-model="email" type="text" required autocomplete="username" />
          </div>
          <div class="space-y-1.5">
            <Label for="password">Senha</Label>
            <Input
              id="password"
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
            />
          </div>
          <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
          </Alert>
          <Button type="submit" class="mt-1 h-10 w-full font-medium" :disabled="submitting">
            {{ submitting ? 'Entrando…' : 'Continuar' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
