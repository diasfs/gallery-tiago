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
    error.value = err instanceof ApiError && err.status === 401 ? 'Invalid email or password.' : 'Login failed.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="admin-root flex min-h-screen items-center justify-center px-4">
    <Card class="w-full max-w-sm border-[var(--border)] bg-[var(--card)]">
      <CardHeader>
        <CardTitle class="font-brand text-2xl">Gallery</CardTitle>
        <CardDescription>Admin sign in</CardDescription>
      </CardHeader>
      <CardContent>
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div class="space-y-2">
            <Label for="email">Email</Label>
            <Input id="email" v-model="email" type="email" required autocomplete="username" />
          </div>
          <div class="space-y-2">
            <Label for="password">Password</Label>
            <Input id="password" v-model="password" type="password" required autocomplete="current-password" />
          </div>
          <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
          </Alert>
          <Button type="submit" :disabled="submitting">
            {{ submitting ? 'Signing in…' : 'Sign in' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
