import { ref } from 'vue'

const toast = ref({
    show: false,
    message: '',
    type: 'info'
})

let timeoutId = null

export function useToast() {
    function showToast(message, type = 'info') {
        if (timeoutId) {
            clearTimeout(timeoutId)
        }

        toast.value = {
            show: true,
            message,
            type
        }

        timeoutId = setTimeout(() => {
            toast.value.show = false
        }, 3000)
    }

    return {
        toast,
        showToast
    }
}
