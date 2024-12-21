<template>
  <div class="sq-actions">
    <button class="sq-button sq-primary" :disabled="loading" @click="$emit('add')">
      <span class="sq-icon">+</span>
      {{ translations.newService }}
    </button>

    <button class="sq-button sq-warning" :disabled="loading" @click="handleReset">
      <span class="sq-icon">↺</span>
      {{ translations.reset }}
    </button>

    <button class="sq-button sq-danger" :disabled="loading" @click="handleRecreate">
      <span class="sq-icon">⟲</span>
      {{ translations.recreate }}
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  loading: {
    type: Boolean,
    default: false
  },
})

const emit = defineEmits(['add', 'reset', 'recreate'])

const translations = {
  newService: window.serviceQueueAjax?.translations?.newService ?? 'New Service',
  reset: window.serviceQueueAjax?.translations?.reset ?? 'Reset All',
  recreate: window.serviceQueueAjax?.translations?.recreate ?? 'Recreate Table',
  confirmReset: window.serviceQueueAjax?.translations?.confirmReset ?? 'Are you sure you want to reset all services?',
  confirmRecreate: window.serviceQueueAjax?.translations?.confirmRecreate ?? 'Are you sure you want to recreate the table?'
}

function handleReset() {
  if (confirm(translations.confirmReset)) {
    emit('reset')
  }
}

function handleRecreate() {
  if (confirm(translations.confirmRecreate)) {
    emit('recreate')
  }
}
</script>

<style scoped>
.sq-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.sq-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  font-size: 1.2em;
  line-height: 1;
}

/* Hover states */
.sq-button:not(:disabled):hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Disabled states */
.sq-button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
  transform: none !important;
  box-shadow: none !important;
}

/* Focus states */
.sq-button:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.5);
}

/* Active states */
.sq-button:active:not(:disabled) {
  transform: translateY(0);
  box-shadow: none;
}

@media (max-width: 768px) {
  .sq-actions {
    flex-direction: column;
    width: 100%;
  }

  .sq-button {
    width: 100%;
  }
}
</style>
