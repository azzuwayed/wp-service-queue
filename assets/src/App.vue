<template>
  <error-boundary>
    <div class="service-queue-app">
      <div class="sq-header">
        <stats-panel :stats="stats" />
        <action-panel :loading="isLoading" @add="addService" @reset="resetServices" @recreate="recreateTable" />
      </div>

      <div class="sq-content">
        <transition-group name="sq-list" tag="div" class="sq-services" :key="updateKey">
          <service-card v-for="service in sortedServices" :key="service.service_id" :service="service" />
        </transition-group>
      </div>

      <toast-message v-model:show="toast.show" :message="toast.message" :type="toast.type" />

      <loading-spinner v-if="isLoading" />
    </div>
  </error-boundary>
</template>

<script setup>
import ErrorBoundary from './components/ErrorBoundary.vue'
import { computed, onMounted, onUnmounted, ref } from "vue"
import StatsPanel from "./components/StatsPanel.vue"
import ActionPanel from "./components/ActionPanel.vue"
import ServiceCard from "./components/ServiceCard.vue"
import ToastMessage from "./components/ToastMessage.vue"
import LoadingSpinner from "./components/LoadingSpinner.vue"
import { useServices } from "./composables/useServices"
import { useToast } from "./composables/useToast"

const updateKey = ref(0)

const {
  services,
  stats,
  isLoading,
  startPolling,
  stopPolling,
  addService,
  resetServices,
  recreateTable,
} = useServices()

const { toast } = useToast()

const sortedServices = computed(() => {
  // Force re-render of transition group when services update
  updateKey.value++

  return [...services.value].sort((a, b) => {
    // Sort by status priority
    const statusOrder = {
      'in_progress': 0,
      'pending': 1,
      'completed': 2,
      'error': 3
    }

    if (statusOrder[a.status] !== statusOrder[b.status]) {
      return statusOrder[a.status] - statusOrder[b.status]
    }

    // Then by premium status
    if (a.is_premium !== b.is_premium) {
      return b.is_premium ? 1 : -1
    }

    // Then by timestamp
    return new Date(b.timestamp) - new Date(a.timestamp)
  })
})

// Handle visibility changes
function handleVisibilityChange() {
  if (document.hidden) {
    stopPolling()
  } else {
    startPolling()
  }
}

onMounted(() => {
  startPolling()
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onUnmounted(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})

// Error boundary
window.onerror = (message, source, lineno, colno, error) => {
  toast.value = {
    show: true,
    message: "An error occurred. Please refresh the page.",
    type: "error"
  }
  console.error('Service Queue Error:', { message, source, lineno, colno, error })
  return false
}
</script>

<style>
/* Component styles are in style.css */
</style>
