<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="not-found">
    <h1>404</h1>
    <p>Não encontramos essa página.</p>
    <RouterLink to="/">Voltar ao início</RouterLink>
  </section>
</template>

<style scoped>
.not-found {
  text-align: center;
  padding: 4rem 0;
}
</style>
