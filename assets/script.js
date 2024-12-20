// assets/script.js
jQuery(document).ready(function($) {
    let updateInterval;
    const REFRESH_INTERVAL = 5000; // 5 seconds

    function formatTimestamp(timestamp) {
        return new Date(timestamp).toLocaleString();
    }

    function updateRefreshTimestamp() {
        $('#refresh-timestamp').text('Last updated: ' + new Date().toLocaleTimeString());
    }

    function updateTable() {
        $.ajax({
            url: serviceQueue.ajax_url,
            type: 'POST',
            data: {
                action: 'get_service_requests'
            },
            success: function(response) {
                if (response.success) {
                    const requests = response.data;
                    let tableContent = '';

                    if (requests.length === 0) {
                        $('#no-requests-message').show();
                        $('#service-table').hide();
                    } else {
                        $('#no-requests-message').hide();
                        $('#service-table').show();

                        requests.forEach(request => {
                            const progress = request.status === 'completed' ? 100 :
                                           request.status === 'in_progress' ?
                                           Math.floor(Math.random() * 60) + 20 : 0;

                            tableContent += `
                                <tr>
                                    <td>#${request.service_id}</td>
                                    <td>${formatTimestamp(request.timestamp)}</td>
                                    <td><span class="service-status status-${request.status}">
                                        ${request.status.replace('_', ' ')}
                                    </span></td>
                                    <td>${request.processing_time}s</td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-bar-fill" style="width: ${progress}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                    }

                    $('#service-table tbody').html(tableContent);
                    updateRefreshTimestamp();
                }
            }
        });
    }

    $('#service-btn').on('click', function() {
        $(this).prop('disabled', true);

        $.ajax({
            url: serviceQueue.ajax_url,
            type: 'POST',
            data: {
                action: 'add_service_request'
            },
            success: function(response) {
                if (response.success) {
                    updateTable();
                }
                $('#service-btn').prop('disabled', false);
            }
        });
    });

    // Initial update and start interval
    updateTable();
    updateInterval = setInterval(updateTable, REFRESH_INTERVAL);
});
