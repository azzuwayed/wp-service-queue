<template>
  <div class="sq-loading-indicator" role="status">
    <div class="sq-spinner"></div>
    <span class="sq-sr-only">{{ translations.loading }}</span>
  </div>
</template>

<script setup>
const translations = window.serviceQueueAjax.translations;
</script>
