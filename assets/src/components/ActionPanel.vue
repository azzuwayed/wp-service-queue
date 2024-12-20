<template>
  <div class="sq-actions">
    <button
      class="sq-button sq-primary"
      :disabled="loading"
      @click="$emit('add')"
    >
      <span class="sq-icon">+</span>
      New Service
    </button>

    <button
      class="sq-button sq-warning"
      :disabled="loading"
      @click="handleReset"
    >
      <span class="sq-icon">↺</span>
      Reset All
    </button>

    <button
      class="sq-button sq-danger"
      :disabled="loading"
      @click="handleRecreate"
    >
      <span class="sq-icon">⟲</span>
      Recreate Table
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  loading: Boolean,
});

const emit = defineEmits(["add", "reset", "recreate"]);

function handleReset() {
  if (confirm(window.serviceQueueAjax.translations.confirmReset)) {
    emit("reset");
  }
}

function handleRecreate() {
  if (confirm(window.serviceQueueAjax.translations.confirmRecreate)) {
    emit("recreate");
  }
}
</script>
