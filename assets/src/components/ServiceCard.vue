<template>
  <div class="sq-service-card" :class="cardClasses">
    <div class="sq-service-header">
      <div class="sq-service-id-wrapper">
        <span class="sq-service-id">#{{ service.service_id }}</span>
        <span v-if="service.is_premium" class="sq-premium-badge">Premium</span>
      </div>
      <span :class="statusClasses">{{ formattedStatus }}</span>
    </div>

    <div class="sq-progress">
      <div class="sq-progress-bar" :style="{ width: `${service.progress}%` }"
        :class="{ 'sq-progress-error': service.status === 'error' }"></div>
    </div>

    <div class="sq-service-info">
      <small class="sq-timestamp">{{ formattedDate }}</small>
      <small class="sq-progress-text">
        <template v-if="service.status === 'pending' && service.queue_position > 1">
          {{ service.queue_position - 1 }} services ahead in queue
        </template>
        <template v-else-if="service.status !== 'error'">
          Progress: {{ Math.round(service.progress) }}%
        </template>
      </small>
    </div>

    <div v-if="service.error_message" class="sq-error-message">
      {{ service.error_message }}
    </div>

    <div v-if="service.retries > 0" class="sq-retry-info">
      <span class="sq-retry-count">
        {{ translations.retryAttempt }}: {{ service.retries }}/{{ maxRetries }}
      </span>
      <span v-if="service.status === 'in_progress'" class="sq-retry-status">
        {{ translations.retrying }}
      </span>
    </div>

    <div v-if="showEstimatedTime" class="sq-estimated-time">
      {{ estimatedTimeMessage }}
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue"

const props = defineProps({
  service: {
    type: Object,
    required: true,
  },
})

const translations = window.serviceQueueAjax.translations
const maxRetries = window.serviceQueueAjax.maxRetries || 3

const cardClasses = computed(() => ({
  'sq-pulse': props.service.status === 'in_progress',
  'sq-error': props.service.status === 'error',
  'sq-premium': props.service.is_premium
}))

const statusClasses = computed(() => [
  "sq-status",
  `sq-status-${props.service.status}`,
])

const formattedStatus = computed(() =>
  props.service.status === 'in_progress' && props.service.retries > 0
    ? translations.retrying
    : translations[props.service.status] || props.service.status.replace("_", " ")
)

const formattedDate = computed(() =>
  new Date(props.service.timestamp).toLocaleString()
)

const showEstimatedTime = computed(() =>
  props.service.status === 'pending' ||
  props.service.status === 'in_progress'
)

const estimatedTimeMessage = computed(() => {
  if (props.service.status === 'pending') {
    const estimatedMinutes = Math.ceil(
      (props.service.queue_position * props.service.processing_time) / 60
    )
    return `${translations.estimatedWait}: ${estimatedMinutes} ${translations.minutes}`
  } else if (props.service.status === 'in_progress') {
    const remainingTime = Math.ceil(
      ((100 - props.service.progress) * props.service.processing_time) / 100 / 60
    )
    return `${translations.estimatedCompletion}: ${remainingTime} ${translations.minutes}`
  }
  return ''
})
</script>
