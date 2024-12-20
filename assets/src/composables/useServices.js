import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useToast } from './useToast'

export function useServices() {
    const services = ref([])
    const isLoading = ref(false)
    const pollInterval = ref(null)
    const { showToast } = useToast()

    const stats = computed(() => ({
        pending: services.value.filter(s => s.status === 'pending').length,
        in_progress: services.value.filter(s => s.status === 'in_progress').length,
        completed: services.value.filter(s => s.status === 'completed').length
    }))

    // Add visibility tracking
    const isVisible = ref(document.visibilityState === 'visible')

    // Handle visibility change
    function handleVisibilityChange() {
        isVisible.value = document.visibilityState === 'visible'
        if (isVisible.value) {
            startPolling() // Resume polling when visible
        } else {
            stopPolling() // Stop polling when hidden
        }
    }

    async function fetchServices() {
        try {
            const response = await fetch(serviceQueueAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_service_requests',
                    nonce: serviceQueueAjax.nonce
                })
            })

            const data = await response.json()
            if (data.success) {
                services.value = data.data
            }
        } catch (error) {
            showToast(error.message, 'error')
        }
    }

async function addService() {
    try {
        const response = await fetch(serviceQueueAjax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'add_service_request',
                nonce: serviceQueueAjax.nonce
            })
        });

        const data = await response.json();
        if (data.success) {
            showToast(data.data.message, 'success');
            await fetchServices();
        } else {
            throw new Error(data.data.message);
        }
    } catch (error) {
        showToast(error.message, 'error');
    }
}

    async function resetServices() {
        try {
            isLoading.value = true
            const response = await fetch(serviceQueueAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'reset_services',
                    nonce: serviceQueueAjax.nonce
                })
            })

            const data = await response.json()
            if (data.success) {
                showToast(data.data, 'success')
                services.value = []
            } else {
                throw new Error(data.data.message)
            }
        } catch (error) {
            showToast(error.message, 'error')
        } finally {
            isLoading.value = false
        }
    }

    async function recreateTable() {
        try {
            isLoading.value = true
            const response = await fetch(serviceQueueAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'recreate_table',
                    nonce: serviceQueueAjax.nonce
                })
            })

            const data = await response.json()
            if (data.success) {
                showToast(data.data, 'success')
                services.value = []
            } else {
                throw new Error(data.data.message)
            }
        } catch (error) {
            showToast(error.message, 'error')
        } finally {
            isLoading.value = false
        }
    }

    function startPolling() {
        if (!pollInterval.value && isVisible.value) {
            fetchServices()
            pollInterval.value = setInterval(fetchServices, 2000)
        }
    }

    function stopPolling() {
        if (pollInterval.value) {
            clearInterval(pollInterval.value)
            pollInterval.value = null
        }
    }

    // Add event listener for visibility changes
    onMounted(() => {
        document.addEventListener('visibilitychange', handleVisibilityChange)
    })

    // Clean up event listener
    onUnmounted(() => {
        document.removeEventListener('visibilitychange', handleVisibilityChange)
        stopPolling()
    })

    return {
        services,
        stats,
        isLoading,
        addService,
        resetServices,
        recreateTable,
        startPolling,
        stopPolling
    }
}
