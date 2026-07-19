<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, adminApi } from '../../api/client'

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
  <section class="login">
    <h1>Admin login</h1>
    <form class="login__form" @submit.prevent="submit">
      <label class="login__field">
        <span>Email</span>
        <input v-model="email" type="email" required autocomplete="username" />
      </label>
      <label class="login__field">
        <span>Password</span>
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>
      <p v-if="error" class="login__error">{{ error }}</p>
      <button type="submit" :disabled="submitting">{{ submitting ? 'Signing in…' : 'Sign in' }}</button>
    </form>
  </section>
</template>

<style scoped>
.login {
  max-width: 360px;
  margin: 3rem auto;
}

.login__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-top: 1.5rem;
}

.login__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}

.login__field input {
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #111;
  color: inherit;
}

.login__error {
  color: #f87171;
  margin: 0;
  font-size: 0.85rem;
}

button {
  padding: 0.6rem 1rem;
  border-radius: 6px;
  border: none;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
