<template>
  <div class="sq-system-status">
    <div class="sq-status-box" :class="loadLevelClass">
      <div class="sq-status-header">
        <span class="sq-status-label">{{ translations.loadLevel }}</span>
        <span class="sq-status-value">{{ loadLevel }}</span>
      </div>

      <div class="sq-load-details">
        <span class="sq-load-item">
          <span class="sq-load-label">{{ translations.systemLoad }}:</span>
          <span class="sq-load-value">{{ status.system_load }}%</span>
        </span>
        <span class="sq-load-item">
          <span class="sq-load-label">{{ translations.queueSize }}:</span>
          <span class="sq-load-value">{{ status.queue_size }}</span>
        </span>
      </div>

      <div class="sq-limits">
        <div class="sq-limit-item">
          <font-awesome-icon icon="globe" class="sq-limit-icon" />
          <span class="sq-limit-label">{{ translations.globalLimit }}</span>
          <span class="sq-limit-value">{{ status.current_limits?.global || 0 }}</span>
        </div>
        <div class="sq-limit-item">
          <font-awesome-icon icon="crown" class="sq-limit-icon" />
          <span class="sq-limit-label">{{ translations.premiumLimit }}</span>
          <span class="sq-limit-value">{{ status.current_limits?.premium || 0 }}</span>
        </div>
        <div class="sq-limit-item">
          <font-awesome-icon icon="user" class="sq-limit-icon" />
          <span class="sq-limit-label">{{ translations.freeLimit }}</span>
          <span class="sq-limit-value">{{ status.current_limits?.free || 0 }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const translations = window.serviceQueueAjax.translations;
const status = ref({
  load_level: 'low',
  system_load: 0,
  current_limits: {
    global: 0,
    premium: 0,
    free: 0
  },
  queue_size: 0,
  last_change: null
});

const pollInterval = ref(null);

const loadLevel = computed(() => {
  return status.value.load_level.charAt(0).toUpperCase() + status.value.load_level.slice(1);
});

const loadLevelClass = computed(() => {
  return {
    'sq-status-low': status.value.load_level === 'low',
    'sq-status-medium': status.value.load_level === 'medium',
    'sq-status-high': status.value.load_level === 'high'
  };
});

async function fetchStatus() {
  try {
    const response = await fetch(serviceQueueAjax.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'get_system_status',
        nonce: serviceQueueAjax.nonce
      })
    });

    const data = await response.json();
    if (data.success) {
      status.value = data.data;
    }
  } catch (error) {
    console.error('Error fetching system status:', error);
  }
}

function startPolling() {
  fetchStatus();
  pollInterval.value = setInterval(fetchStatus, 5000);
}

function stopPolling() {
  if (pollInterval.value) {
    clearInterval(pollInterval.value);
    pollInterval.value = null;
  }
}

onMounted(() => {
  startPolling();
});

onUnmounted(() => {
  stopPolling();
});
</script>
