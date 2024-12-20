<template>
  <div class="sq-service-card" :class="{ 'sq-pulse': service.status === 'in_progress' }">
    <div class="sq-service-header">
      <div class="sq-service-id-wrapper">
        <span class="sq-service-id">#{{ service.service_id }}</span>
        <span v-if="service.is_premium" class="sq-premium-badge">Premium</span>
      </div>
      <span :class="statusClasses">{{ formattedStatus }}</span>
    </div>

    <div class="sq-progress">
      <div class="sq-progress-bar" :style="{ width: `${service.progress}%` }"></div>
    </div>

    <div class="sq-service-info">
      <small class="sq-timestamp">{{ formattedDate }}</small>
      <small class="sq-progress-text">
        <template v-if="service.status === 'pending' && service.queue_position > 1">
          {{ service.queue_position - 1 }} services ahead in queue
        </template>
        <template v-else>
          Progress: {{ Math.round(service.progress) }}%
        </template>
      </small>
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  service: {
    type: Object,
    required: true,
  },
});

const statusClasses = computed(() => [
  "sq-status",
  `sq-status-${props.service.status}`,
]);

const formattedStatus = computed(() => props.service.status.replace("_", " "));

const formattedDate = computed(() =>
  new Date(props.service.timestamp).toLocaleString()
);
</script>
