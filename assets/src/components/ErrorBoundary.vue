<template>
    <div v-if="error" class="sq-error-boundary">
        {{ translations.errorMessage }}
    </div>
    <slot v-else></slot>
</template>

<script setup>
import { ref, onErrorCaptured } from 'vue'

const error = ref(null)
const translations = window.serviceQueueAjax.translations

onErrorCaptured((err) => {
    error.value = err
    console.error('Service Queue Component Error:', err)
    return false
})
</script>
