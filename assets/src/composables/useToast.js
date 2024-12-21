import { ref } from 'vue'

const toast = ref({
    show: false,
    message: '',
    type: 'info'
})

let timeoutId = null

export function useToast() {
    function showToast(message, type = 'info', duration = 3000) {
        // Clear any existing timeout
        if (timeoutId) {
            clearTimeout(timeoutId)
        }

        // Update toast state
        toast.value = {
            show: true,
            message,
            type
        }

        // Set new timeout
        timeoutId = setTimeout(() => {
            hideToast()
        }, duration)
    }

    function hideToast() {
        toast.value.show = false
        if (timeoutId) {
            clearTimeout(timeoutId)
            timeoutId = null
        }
    }

    function updateToastDuration(duration) {
        if (toast.value.show && timeoutId) {
            clearTimeout(timeoutId)
            timeoutId = setTimeout(() => {
                hideToast()
            }, duration)
        }
    }

    // Clean up on component unmount
    function cleanup() {
        if (timeoutId) {
            clearTimeout(timeoutId)
            timeoutId = null
        }
        toast.value = {
            show: false,
            message: '',
            type: 'info'
        }
    }

    return {
        toast,
        showToast,
        hideToast,
        updateToastDuration,
        cleanup
    }
}

// Create a shared instance for global usage
const globalToast = useToast()

// Export both the composable and the global instance
export { globalToast }
