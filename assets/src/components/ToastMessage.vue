<template>
  <Transition name="sq-fade">
    <div v-if="show" class="sq-toast" :class="toastClass" role="alert">
      {{ message }}
    </div>
  </Transition>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  show: Boolean,
  message: String,
  type: {
    type: String,
    default: "info",
  },
});

const toastClass = computed(() => `sq-toast-${props.type}`);
</script>
