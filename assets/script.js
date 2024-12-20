class ServiceQueue {
    constructor() {
        this.services = new Map();
        this.ws = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.pollingInterval = null;
        this.isProcessing = false;
        this.debounceTimeout = null;
        this.fetchDebounceTime = 200;
        this.pingInterval = null;
        this.init();
    }

    init() {
        try {
            this.bindElements();
            this.bindEvents();
            if (serviceQueueWS.enabled === '1') {
                this.initWebSocket();
            } else {
                this.startPolling();
            }
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

    initWebSocket() {
        const protocol = serviceQueueWS.secure ? 'wss:' : 'ws:';
        const wsUrl = `${protocol}//${serviceQueueWS.host}:${serviceQueueWS.port}`;

        try {
            this.ws = new WebSocket(wsUrl);

            this.ws.onopen = () => {
                console.log('WebSocket connected');
                this.reconnectAttempts = 0;
                this.stopPolling();

                this.ws.send(JSON.stringify({
                    action: 'subscribe',
                    queue_id: 'global'
                }));

                this.startPingInterval();
            };

            this.ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    if (data.type === 'service_update') {
                        this.handleServiceUpdate(data.data);
                    }
                } catch (error) {
                    console.error('WebSocket message error:', error);
                }
            };

            this.ws.onclose = () => {
                console.log('WebSocket disconnected, falling back to polling');
                this.stopPingInterval();
                this.startPolling();

                if (this.reconnectAttempts < this.maxReconnectAttempts) {
                    setTimeout(() => {
                        this.reconnectAttempts++;
                        this.initWebSocket();
                    }, 3000);
                }
            };

            this.ws.onerror = (error) => {
                console.log('WebSocket connection failed, using polling instead');
                this.startPolling();
            };
        } catch (error) {
            console.log('WebSocket initialization failed, using polling');
            this.startPolling();
        }
    }

    handleVisibilityChange() {
        if (document.hidden) {
            this.stopPolling();
            this.stopPingInterval();
        } else {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
                this.startPolling();
            }
            this.fetchServices();
        }
    }

    startPingInterval() {
        this.pingInterval = setInterval(() => {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.ws.send(JSON.stringify({ action: 'ping' }));
            }
        }, 30000);
    }

    stopPingInterval() {
        if (this.pingInterval) {
            clearInterval(this.pingInterval);
            this.pingInterval = null;
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
        clearTimeout(this.debounceTimeout);

        this.debounceTimeout = setTimeout(async () => {
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
        }, this.fetchDebounceTime);
    }

    handleServiceUpdate(service) {
        const newService = {
            ...service,
            progress: parseInt(service.progress) || 0
        };

        const existingService = this.services.get(service.service_id);
        if (!existingService || JSON.stringify(existingService) !== JSON.stringify(newService)) {
            this.services.set(service.service_id, newService);
            this.updateServiceCard(service.service_id, newService);
            this.updateStats();
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

        newServices.forEach((newService, id) => {
            const existingService = this.services.get(id);
            if (!existingService || JSON.stringify(existingService) !== JSON.stringify(newService)) {
                this.updateServiceCard(id, newService);
            }
        });

        this.services.forEach((_, id) => {
            if (!newServices.has(id)) {
                const card = document.querySelector(`[data-service-id="${id}"]`);
                if (card) {
                    card.remove();
                }
            }
        });

        this.services = newServices;
        this.updateStats();
    }

    updateServiceCard(serviceId, service) {
        const card = document.querySelector(`[data-service-id="${serviceId}"]`);
        if (card) {
            this.updateExistingCard(card, service);
        } else {
            const cardHTML = this.renderServiceCard(service);
            this.servicesList.insertAdjacentHTML('afterbegin', cardHTML);
        }
    }

    updateExistingCard(card, service) {
        const statusElement = card.querySelector('.sq-status');
        const progressBar = card.querySelector('.sq-progress-bar');
        const progressText = card.querySelector('.sq-service-info small:last-child');

        statusElement.className = `sq-status sq-status-${service.status}`;
        statusElement.textContent = service.status.replace('_', ' ');

        progressBar.style.width = `${service.progress}%`;
        progressText.textContent = `Progress: ${Math.round(service.progress)}%`;

        card.className = `sq-service-card ${service.status === 'in_progress' ? 'sq-pulse' : ''}`;
    }

    renderServiceCard(service) {
        const statusClass = `sq-status-${service.status}`;
        const pulseClass = service.status === 'in_progress' ? 'sq-pulse' : '';

        return `
            <div class="sq-service-card ${pulseClass}" data-service-id="${service.service_id}">
                <div class="sq-service-header">
                    <span class="sq-service-id">#${service.service_id}</span>
                    <span class="sq-status ${statusClass}">
                        ${service.status.replace('_', ' ')}
                    </span>
                </div>
                <div class="sq-progress">
                    <div class="sq-progress-bar" style="width: ${service.progress}%"></div>
                </div>
                <div class="sq-service-info">
                    <small>Created: ${new Date(service.timestamp).toLocaleString()}</small>
                    <small>Progress: ${Math.round(service.progress)}%</small>
                </div>
            </div>
        `;
    }

    updateStats() {
        const stats = {
            pending: 0,
            in_progress: 0,
            completed: 0
        };

        this.services.forEach(service => {
            stats[service.status]++;
        });

        Object.entries(stats).forEach(([key, value]) => {
            const element = this.statsElements[key === 'in_progress' ? 'progress' : key];
            if (element) element.textContent = value;
        });
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
        this.stopPingInterval();
        if (this.ws) {
            this.ws.close();
        }
        this.addButton?.removeEventListener('click', this.addService);
        this.resetButton?.removeEventListener('click', this.resetServices);
        this.recreateButton?.removeEventListener('click', this.recreateTable);
        document.removeEventListener('visibilitychange', this.handleVisibilityChange);
    }
}

jQuery(document).ready(() => {
    window.serviceQueue = new ServiceQueue();
});
