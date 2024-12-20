<template>
  <div
    class="sq-service-card"
    :class="{ 'sq-pulse': service.status === 'in_progress' }"
  >
    <div class="sq-service-header">
      <span class="sq-service-id">#{{ service.service_id }}</span>
      <span :class="statusClasses">{{ formattedStatus }}</span>
    </div>

    <div class="sq-progress">
      <div
        class="sq-progress-bar"
        :style="{ width: `${service.progress}%` }"
      ></div>
    </div>

    <div class="sq-service-info">
      <small class="sq-timestamp">{{ formattedDate }}</small>
      <small class="sq-progress-text">
        Progress: {{ Math.round(service.progress) }}%
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
