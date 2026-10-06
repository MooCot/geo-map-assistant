(function () {
    'use strict';

    var element = document.getElementById('gma-map');

    if (!element || typeof L === 'undefined' || !window.gmaMap) {
        return;
    }

    var config = window.gmaMap;
    // wp_localize_script stringifies top-level scalars, so the zoom arrives as "5".
    var map = L.map(element).setView(config.center, Number(config.zoom));

    L.tileLayer(config.tileUrl, {
        attribution: config.attribution,
        maxZoom: 19,
    }).addTo(map);

    if (config.markers.length === 0) {
        return;
    }

    var markers = config.markers.map(function (point) {
        var marker = L.marker([point.lat, point.lng]);

        // Built with DOM nodes, not HTML strings, so a post title can never inject markup.
        var popup = document.createElement('div');
        var link = document.createElement('a');
        link.href = point.url;
        link.textContent = point.title;
        popup.appendChild(link);

        marker.bindPopup(popup);

        return marker.addTo(map);
    });

    map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [20, 20] });
})();
