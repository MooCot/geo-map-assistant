(function () {
    'use strict';

    var config = window.gmaDashboard;

    if (!config) {
        return;
    }

    var MAX_DRAIN_ITERATIONS = 30;
    var body = document.getElementById('gma-jobs-body');
    var feedback = document.getElementById('gma-dashboard-feedback');
    var queueButton = document.getElementById('gma-queue-all');
    var batchButton = document.getElementById('gma-process-batch');

    function post(action, nonce, extra) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', nonce);

        Object.keys(extra || {}).forEach(function (key) {
            formData.append(key, extra[key]);
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (response) {
            return response.json();
        });
    }

    function say(message) {
        feedback.textContent = message;
    }

    function failureMessage(json) {
        return (json && json.data && json.data.message) || 'request failed';
    }

    function updateCounts(counts) {
        document.querySelectorAll('[data-count]').forEach(function (node) {
            var key = node.getAttribute('data-count');
            node.textContent = counts[key] !== undefined ? counts[key] : 0;
        });
    }

    function cell(text) {
        var td = document.createElement('td');
        td.textContent = text;

        return td;
    }

    function renderJobs(jobs) {
        body.textContent = '';

        if (jobs.length === 0) {
            var empty = document.createElement('tr');
            var emptyCell = cell('No geocoding jobs yet. Add an address to a post, or use "Queue all".');
            emptyCell.colSpan = 7;
            empty.appendChild(emptyCell);
            body.appendChild(empty);

            return;
        }

        jobs.forEach(function (job) {
            var row = document.createElement('tr');

            var titleCell = document.createElement('td');
            if (job.edit_url) {
                var link = document.createElement('a');
                link.href = job.edit_url;
                link.textContent = job.post_title;
                titleCell.appendChild(link);
            } else {
                titleCell.textContent = job.post_title;
            }
            row.appendChild(titleCell);

            row.appendChild(cell(job.address));
            row.appendChild(cell(job.status));
            row.appendChild(cell(String(job.attempts)));
            row.appendChild(cell(job.lat !== null ? job.lat + ', ' + job.lng : '—'));

            var errorText = job.error_message || '';
            if (job.next_retry_at) {
                errorText += (errorText ? ' ' : '') + 'next: ' + job.next_retry_at + ' UTC';
            }
            row.appendChild(cell(errorText));

            var actionCell = document.createElement('td');
            if (job.retryable) {
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button gma-retry';
                retry.setAttribute('data-job-id', String(job.id));
                retry.textContent = 'Retry';
                actionCell.appendChild(retry);
            }
            row.appendChild(actionCell);

            body.appendChild(row);
        });
    }

    function refresh() {
        return post('gma_dashboard_data', config.dataNonce).then(function (json) {
            if (json.success) {
                updateCounts(json.data.counts);
                renderJobs(json.data.jobs);
            }
        }).catch(function () {
            // Transient network error during polling: the next tick retries.
        });
    }

    function drain(iteration) {
        return post('gma_process_batch', config.batchNonce).then(function (json) {
            if (!json.success) {
                throw new Error(failureMessage(json));
            }

            var stats = json.data.stats;
            updateCounts(json.data.counts);
            say('Processed ' + stats.processed + ' (done ' + stats.done + ', not found ' + stats.not_found + ', retry ' + stats.retry + ', failed ' + stats.failed + ')');

            if (stats.stopped) {
                feedback.textContent += ' Stopped: provider unavailable.';
                return;
            }

            if (stats.processed === 0) {
                return;
            }

            if (iteration >= MAX_DRAIN_ITERATIONS) {
                say('Stopped after ' + MAX_DRAIN_ITERATIONS + ' batches. Click again to continue.');
                return;
            }

            return drain(iteration + 1);
        });
    }

    queueButton.addEventListener('click', function () {
        queueButton.disabled = true;
        say('Queuing…');

        post('gma_queue_all', config.queueNonce).then(function (json) {
            if (!json.success) {
                throw new Error(failureMessage(json));
            }

            updateCounts(json.data.counts);
            say('Queued ' + json.data.queued + ' of ' + json.data.total + ' posts. Processing…');

            return drain(1);
        }).catch(function (error) {
            say(error.message);
        }).finally(function () {
            queueButton.disabled = false;
            refresh();
        });
    });

    batchButton.addEventListener('click', function () {
        batchButton.disabled = true;
        say('Processing…');

        drain(1).catch(function (error) {
            say(error.message);
        }).finally(function () {
            batchButton.disabled = false;
            refresh();
        });
    });

    body.addEventListener('click', function (event) {
        var button = event.target.closest('.gma-retry');

        if (!button) {
            return;
        }

        button.disabled = true;

        post('gma_retry_job', config.retryNonce, { job_id: button.getAttribute('data-job-id') }).then(function (json) {
            say(json.success ? 'Requeued.' : failureMessage(json));
        }).finally(refresh);
    });

    setInterval(refresh, config.pollMs);
})();
