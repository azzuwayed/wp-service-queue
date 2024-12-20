<template>
  <div class="service-queue-app">
    <div class="sq-header">
      <stats-panel :stats="stats" />
      <action-panel
        :loading="isLoading"
        @add="addService"
        @reset="resetServices"
        @recreate="recreateTable"
      />
    </div>

    <div class="sq-content">
      <transition-group name="sq-list" tag="div" class="sq-services">
        <service-card
          v-for="service in services"
          :key="service.service_id"
          :service="service"
        />
      </transition-group>
    </div>

    <toast-message
      v-model:show="toast.show"
      :message="toast.message"
      :type="toast.type"
    />

    <loading-spinner v-if="isLoading" />
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from "vue";
import StatsPanel from "./components/StatsPanel.vue";
import ActionPanel from "./components/ActionPanel.vue";
import ServiceCard from "./components/ServiceCard.vue";
import ToastMessage from "./components/ToastMessage.vue";
import LoadingSpinner from "./components/LoadingSpinner.vue";
import { useServices } from "./composables/useServices";
import { useToast } from "./composables/useToast";

const {
  services,
  stats,
  isLoading,
  startPolling,
  stopPolling,
  addService,
  resetServices,
  recreateTable,
} = useServices();

const { toast } = useToast();

onMounted(() => {
  startPolling();
});

onUnmounted(() => {
  stopPolling();
});
</script>
