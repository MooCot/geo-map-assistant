<?php
/**
 * Rendered by SettingsPage::render(). Values are read from options with the
 * same defaults the sanitizer falls back to.
 */
$geocoder = (string) get_option('gma_geocoder', \GMA\Admin\SettingsSanitizer::DEFAULT_GEOCODER);
?>
<div class="wrap">
    <h1>Geo Map Settings</h1>
    <p class="description">The demo runs on the mock geocoder by default: no network calls, deterministic coordinates. Switch to Nominatim only when you are ready to respect its usage policy (one request per second, identifying User-Agent).</p>

    <form method="post" action="options.php">
        <?php settings_fields(\GMA\Admin\SettingsPage::GROUP); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="gma_geocoder">Geocoder</label></th>
                <td>
                    <select id="gma_geocoder" name="gma_geocoder">
                        <option value="mock" <?php selected($geocoder, 'mock'); ?>>Mock (demo, no network)</option>
                        <option value="nominatim" <?php selected($geocoder, 'nominatim'); ?>>Nominatim (OpenStreetMap)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gma_user_agent">User-Agent</label></th>
                <td>
                    <input type="text" id="gma_user_agent" name="gma_user_agent" class="regular-text"
                           value="<?php echo esc_attr((string) get_option('gma_user_agent', \GMA\Admin\SettingsSanitizer::DEFAULT_USER_AGENT)); ?>">
                    <p class="description">Sent to Nominatim. Include a way to contact you.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gma_tile_url">Tile server URL</label></th>
                <td>
                    <input type="url" id="gma_tile_url" name="gma_tile_url" class="large-text"
                           value="<?php echo esc_attr((string) get_option('gma_tile_url', \GMA\Admin\SettingsSanitizer::DEFAULT_TILE_URL)); ?>">
                    <p class="description">Must be https and contain {z}, {x} and {y}.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Default map centre</th>
                <td>
                    <label for="gma_map_center_lat">Lat</label>
                    <input type="number" step="any" id="gma_map_center_lat" name="gma_map_center_lat"
                           value="<?php echo esc_attr((string) get_option('gma_map_center_lat', \GMA\Admin\SettingsSanitizer::DEFAULT_LAT)); ?>">
                    <label for="gma_map_center_lng">Lng</label>
                    <input type="number" step="any" id="gma_map_center_lng" name="gma_map_center_lng"
                           value="<?php echo esc_attr((string) get_option('gma_map_center_lng', \GMA\Admin\SettingsSanitizer::DEFAULT_LNG)); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gma_map_zoom">Default zoom</label></th>
                <td>
                    <input type="number" min="1" max="18" id="gma_map_zoom" name="gma_map_zoom"
                           value="<?php echo esc_attr((string) get_option('gma_map_zoom', \GMA\Admin\SettingsSanitizer::DEFAULT_ZOOM)); ?>">
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
