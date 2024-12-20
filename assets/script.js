class ServiceQueue {
    constructor() {
        this.services = new Map();
        this.pollingInterval = null;
        this.isProcessing = false;
        this.init();
    }

    init() {
        try {
            this.bindElements();
            this.bindEvents();
            this.startPolling();
            this.fetchServices();
        } catch (error) {
            this.showToast('Failed to initialize application', 'error');
            console.error('Initialization error:', error);
        }
    }

    bindElements() {
        this.addButton = document.getElementById('add-service');
        this.resetButton = document.getElementById('reset-services');
        this.recreateButton = document.getElementById('recreate-table');
        this.servicesList = document.getElementById('services-list');
        this.toast = document.getElementById('toast');

        this.statsElements = {
            pending: document.getElementById('pending-count'),
            progress: document.getElementById('progress-count'),
            completed: document.getElementById('completed-count')
        };
    }

    bindEvents() {
        this.addButton.addEventListener('click', () => this.addService());
        this.resetButton.addEventListener('click', () => this.resetServices());
        this.recreateButton.addEventListener('click', () => this.recreateTable());
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange());
    }

    handleVisibilityChange() {
        if (document.hidden) {
            this.stopPolling();
        } else {
            this.startPolling();
            this.fetchServices();
        }
    }

    showToast(message, type = 'info') {
        this.toast.textContent = message;
        this.toast.className = `sq-toast sq-toast-${type} show`;
        setTimeout(() => {
            this.toast.className = 'sq-toast';
        }, 3000);
    }

    async addService() {
        if (this.isProcessing) return;

        this.isProcessing = true;
        this.addButton.disabled = true;
        this.addButton.classList.add('sq-loading');

        try {
            const response = await jQuery.ajax({
                url: serviceQueueAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'add_service_request',
                    nonce: serviceQueueAjax.nonce
                }
            });

            if (response.success) {
                this.showToast('Service added successfully', 'success');
                await this.fetchServices();
            } else {
                throw new Error(response.data || 'Failed to add service');
            }
        } catch (error) {
            this.showToast(error.message, 'error');
            console.error('Add service error:', error);
        } finally {
            this.isProcessing = false;
            this.addButton.disabled = false;
            this.addButton.classList.remove('sq-loading');
        }
    }

    async resetServices() {
        if (this.isProcessing || !confirm('Are you sure you want to reset all services?')) return;

        this.isProcessing = true;
        this.resetButton.disabled = true;
        this.resetButton.classList.add('sq-loading');

        try {
            const response = await jQuery.ajax({
                url: serviceQueueAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'reset_services',
                    nonce: serviceQueueAjax.nonce
                }
            });

            if (response.success) {
                this.services.clear();
                this.render();
                this.showToast('All services reset successfully', 'success');
            } else {
                throw new Error(response.data || 'Failed to reset services');
            }
        } catch (error) {
            this.showToast(error.message, 'error');
            console.error('Reset error:', error);
        } finally {
            this.isProcessing = false;
            this.resetButton.disabled = false;
            this.resetButton.classList.remove('sq-loading');
        }
    }

    async recreateTable() {
        if (this.isProcessing || !confirm('Are you sure you want to recreate the table? All data will be lost.')) return;

        this.isProcessing = true;
        this.recreateButton.disabled = true;
        this.recreateButton.classList.add('sq-loading');

        try {
            const response = await jQuery.ajax({
                url: serviceQueueAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'recreate_table',
                    nonce: serviceQueueAjax.nonce
                }
            });

            if (response.success) {
                this.services.clear();
                this.render();
                this.showToast('Table recreated successfully', 'success');
            } else {
                throw new Error(response.data || 'Failed to recreate table');
            }
        } catch (error) {
            this.showToast(error.message, 'error');
            console.error('Recreate table error:', error);
        } finally {
            this.isProcessing = false;
            this.recreateButton.disabled = false;
            this.recreateButton.classList.remove('sq-loading');
        }
    }

    async fetchServices() {
        try {
            const response = await jQuery.ajax({
                url: serviceQueueAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_service_requests',
                    nonce: serviceQueueAjax.nonce
                }
            });

            if (response.success && Array.isArray(response.data)) {
                this.updateServices(response.data);
            } else {
                throw new Error('Invalid response format');
            }
        } catch (error) {
            console.error('Fetch services error:', error);
        }
    }

    updateServices(services) {
        const newServices = new Map();
        services.forEach(service => {
            newServices.set(service.service_id, {
                ...service,
                progress: parseInt(service.progress) || 0
            });
        });
        this.services = newServices;
        this.render();
    }

    updateStats() {
        const stats = {
            pending: 0,
            in_progress: 0,
            completed: 0
        };

        this.services.forEach(service => {
            if (service.status === 'in_progress') {
                stats.in_progress++;
            } else {
                stats[service.status]++;
            }
        });

        Object.entries(stats).forEach(([key, value]) => {
            const element = this.statsElements[key === 'in_progress' ? 'progress' : key];
            if (element) element.textContent = value;
        });
    }

    render() {
        if (!this.servicesList) return;

        this.servicesList.innerHTML = Array.from(this.services.values())
            .map(service => this.renderServiceCard(service))
            .join('');

        this.updateStats();
    }

renderServiceCard(service) {
    const statusClass = `sq-status-${service.status}`;
    const pulseClass = service.status === 'in_progress' ? 'sq-pulse' : '';
    const progressClass = `sq-progress-${service.status}`;

    return `
        <div class="sq-service-card ${pulseClass}">
            <div class="sq-service-header">
                <span class="sq-service-id">#${service.service_id}</span>
                <span class="sq-status ${statusClass}">
                    ${service.status.replace('_', ' ')}
                </span>
            </div>
            <div class="sq-progress ${progressClass}">
                <div class="sq-progress-bar" style="width: ${service.progress}%"></div>
            </div>
            <div class="sq-service-info">
                <small>Created: ${new Date(service.timestamp).toLocaleString()}</small>
                <small>Progress: ${Math.round(service.progress)}%</small>
            </div>
        </div>
    `;
}

    startPolling() {
        if (this.pollingInterval) return;
        this.pollingInterval = setInterval(() => this.fetchServices(), 2000);
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    destroy() {
        this.stopPolling();
        this.addButton?.removeEventListener('click', this.addService);
        this.resetButton?.removeEventListener('click', this.resetServices);
        this.recreateButton?.removeEventListener('click', this.recreateTable);
        document.removeEventListener('visibilitychange', this.handleVisibilityChange);
    }
}

jQuery(document).ready(() => {
    window.serviceQueue = new ServiceQueue();
});
