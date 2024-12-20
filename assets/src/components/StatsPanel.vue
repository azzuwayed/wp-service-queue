<template>
  <div class="sq-stats">
    <div class="sq-stat-box">
      <span class="sq-label">{{ translations.pending }}</span>
      <span class="sq-value">{{ stats.pending }}</span>
    </div>
    <div class="sq-stat-box">
      <span class="sq-label">{{ translations.inProgress }}</span>
      <span class="sq-value">{{ stats.in_progress }}</span>
    </div>
    <div class="sq-stat-box">
      <span class="sq-label">{{ translations.completed }}</span>
      <span class="sq-value">{{ stats.completed }}</span>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  stats: {
    type: Object,
    required: true,
  },
});

const translations = window.serviceQueueAjax.translations;
</script>
