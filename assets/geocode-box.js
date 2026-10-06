(function () {
    'use strict';

    var button = document.getElementById('gma-geocode-now');

    if (!button) {
        return;
    }

    var status = document.getElementById('gma-geo-status');

    button.addEventListener('click', function () {
        button.disabled = true;
        status.textContent = 'working…';

        var formData = new FormData();
        formData.append('action', 'gma_geocode_post');
        formData.append('nonce', gmaGeocodeBox.nonce);
        formData.append('post_id', button.dataset.postId);

        fetch(gmaGeocodeBox.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (json.success) {
                    status.textContent = json.data.status;
                } else {
                    status.textContent = (json.data && json.data.message) || 'failed';
                }
            })
            .catch(function () {
                status.textContent = 'network error';
            })
            .finally(function () {
                button.disabled = false;
            });
    });
})();
