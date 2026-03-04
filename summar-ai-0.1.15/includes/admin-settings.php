<?php
if ( ! defined('ABSPATH') ) { exit; }

/* =========================
 * Admin Panel (Ayarlar)
 * ========================= */

function ag_llm_admin_menu() {
    add_options_page(
        'Antigravity LLM',
        'Antigravity LLM',
        'manage_options',
        'ag-llm-settings',
        'ag_llm_render_settings_page'
    );
}
add_action('admin_menu', 'ag_llm_admin_menu');

function ag_llm_plugin_action_links( $links ) {
    $url = admin_url('options-general.php?page=ag-llm-settings');
    $links[] = '<a href="' . esc_url($url) . '">Ayarlar</a>';

    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(AG_LLM_PLUGIN_FILE), 'ag_llm_plugin_action_links');

/* =========================
 * Sanitizers
 * ========================= */

function ag_llm_sanitize_api_key( $v ) {
    $v = is_string($v) ? trim($v) : '';
    if ( $v === '' ) {
        $should_clear = ! empty($_POST['ag_llm_clear_api_key']);
        if ( $should_clear ) {
            return '';
        }

        $existing = (string) get_option('ag_openai_api_key');
        return $existing;
    }

    return $v;
}

function ag_llm_sanitize_model( $v ) {
    $v = is_string($v) ? trim($v) : '';
    return $v;
}

function ag_llm_sanitize_temperature( $v ) {
    if ( $v === '' || $v === null ) return '';
    if ( ! is_numeric($v) ) return '';
    $f = (float) $v;
    if ( $f < 0 ) $f = 0;
    if ( $f > 2 ) $f = 2;
    return (string) $f;
}

function ag_llm_sanitize_int( $v ) {
    if ( $v === '' || $v === null ) return '';
    $i = is_numeric($v) ? (int) $v : 0;
    if ( $i < 0 ) $i = 0;
    return (string) $i;
}

/**
 * Formda saat giriyoruz; option'da saniye saklıyoruz.
 */
function ag_llm_sanitize_cache_ttl_hours( $hours ) {
    if ( $hours === '' || $hours === null ) return '';
    if ( ! is_numeric($hours) ) return '';
    $h = (float) $hours;
    if ( $h < 0 ) $h = 0;
    $sec = (int) round($h * HOUR_IN_SECONDS);
    return (string) $sec;
}

/* =========================
 * Settings registration
 * ========================= */

function ag_llm_register_settings() {
    register_setting('ag_llm_settings', 'ag_openai_api_key', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_api_key',
        'default' => '',
    ));

    register_setting('ag_llm_settings', 'ag_llm_model', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_model',
        'default' => '',
    ));

    register_setting('ag_llm_settings', 'ag_llm_temperature', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_temperature',
        'default' => '',
    ));

    register_setting('ag_llm_settings', 'ag_llm_max_tokens', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_int',
        'default' => '',
    ));

    // saniye olarak saklanır; formda saat gösterilir
    register_setting('ag_llm_settings', 'ag_llm_cache_ttl_seconds', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_cache_ttl_hours',
        'default' => '',
    ));

    register_setting('ag_llm_settings', 'ag_llm_rate_limit_per_minute', array(
        'type' => 'string',
        'sanitize_callback' => 'ag_llm_sanitize_int',
        'default' => '',
    ));
}
add_action('admin_init', 'ag_llm_register_settings');

/* =========================
 * Notices
 * ========================= */

function ag_llm_admin_notices() {
    if ( ! current_user_can('manage_options') ) return;

    if ( isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'
        && isset($_GET['page']) && $_GET['page'] === 'ag-llm-settings'
    ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html('Ayarlar kaydedildi.') . '</p></div>';
        return;
    }

    if ( empty($_GET['ag_llm_notice']) ) return;

    $notice = sanitize_text_field( (string) $_GET['ag_llm_notice'] );
    $type   = (! empty($_GET['ag_llm_type']) && $_GET['ag_llm_type'] === 'error') ? 'error' : 'success';
    $msg    = '';

    if ( $notice === 'cache_cleared' ) $msg = 'Önbellek temizlendi.';
    if ( $notice === 'cache_clear_fail' ) $msg = 'Önbellek temizlenemedi. (Ayrıntı için PHP error logu)';

    if ( ! $msg ) return;

    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}
add_action('admin_notices', 'ag_llm_admin_notices');

/* =========================
 * Settings page UI
 * ========================= */

function ag_llm_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    $api_key = (string) get_option('ag_openai_api_key');
    $model   = (string) get_option('ag_llm_model');
    $temp    = (string) get_option('ag_llm_temperature');
    $max_t   = (string) get_option('ag_llm_max_tokens');

    $ttl_raw = get_option('ag_llm_cache_ttl_seconds', '');
    $ttl_sec = ($ttl_raw === '' || $ttl_raw === null) ? '' : (int) $ttl_raw;
    $ttl_hours = ($ttl_sec !== '' && $ttl_sec >= 0) ? ($ttl_sec / HOUR_IN_SECONDS) : '';

    $rl = (string) get_option('ag_llm_rate_limit_per_minute');

    ?>
    <div class="wrap">
      <h1>Antigravity LLM</h1>
      <p>Bu panelden API anahtarı ve temel çalışma parametrelerini yönetebilirsin.</p>

      <form method="post" action="options.php">
        <?php settings_fields('ag_llm_settings'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ag_openai_api_key">OpenAI API Key</label></th>
            <td>
              <input type="password" id="ag_openai_api_key" name="ag_openai_api_key" value="" class="regular-text" autocomplete="new-password" />
              <p class="description">
                Yeni anahtar gir. Boş bırakırsan kayıtlı anahtar korunur.
                <?php if ( ! empty($api_key) ) : ?>
                  (Şu anda kayıtlı bir anahtar var.)
                <?php endif; ?>
              </p>
              <label>
                <input type="checkbox" name="ag_llm_clear_api_key" value="1" />
                Kayıtlı API anahtarını temizle
              </label>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_model">Model</label></th>
            <td>
              <input type="text" id="ag_llm_model" name="ag_llm_model" value="<?php echo esc_attr($model); ?>" class="regular-text" placeholder="gpt-4.1-nano" />
              <p class="description">Boşsa varsayılan: <code>gpt-4.1-nano</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_temperature">Temperature</label></th>
            <td>
              <input type="number" step="0.1" min="0" max="2" id="ag_llm_temperature" name="ag_llm_temperature" value="<?php echo esc_attr($temp); ?>" class="small-text" placeholder="0.2" />
              <p class="description">Boşsa varsayılan: <code>0.2</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_max_tokens">Max tokens</label></th>
            <td>
              <input type="number" min="1" id="ag_llm_max_tokens" name="ag_llm_max_tokens" value="<?php echo esc_attr($max_t); ?>" class="small-text" placeholder="420" />
              <p class="description">Boşsa varsayılan: <code>420</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_cache_ttl_seconds">Cache TTL (saat)</label></th>
            <td>
              <input type="number" step="0.5" min="0" id="ag_llm_cache_ttl_seconds" name="ag_llm_cache_ttl_seconds" value="<?php echo esc_attr($ttl_hours); ?>" class="small-text" placeholder="6" />
              <p class="description">0 girersen cache kapatılır. Boşsa varsayılan: <code>6 saat</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_rate_limit_per_minute">Rate limit (dakikada)</label></th>
            <td>
              <input type="number" min="0" id="ag_llm_rate_limit_per_minute" name="ag_llm_rate_limit_per_minute" value="<?php echo esc_attr($rl); ?>" class="small-text" placeholder="6" />
              <p class="description">0 girersen rate limit kapatılır. Boşsa varsayılan: <code>6</code>.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Kaydet'); ?>
      </form>

      <hr />

      <h2>Hızlı İşlemler</h2>

      <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('ag_llm_clear_cache'); ?>
          <input type="hidden" name="action" value="ag_llm_clear_cache" />
          <?php submit_button('Önbelleği Temizle', 'delete', 'submit', false); ?>
          <p class="description" style="margin-top:8px;">Bu eklentinin transient cache ve rate-limit kayıtlarını temizler.</p>
        </form>
      </div>

      <hr />

      <h2>Kullanım</h2>
      <p>Herhangi bir sayfa/yazı içine şu shortcode’u ekleyebilirsin:</p>
      <p><code>[summar-ai]</code></p>
    </div>
    <?php
}

/* =========================
 * Admin actions
 * ========================= */

function ag_llm_handle_admin_clear_cache() {
    if ( ! current_user_can('manage_options') ) wp_die('Yetkisiz');
    check_admin_referer('ag_llm_clear_cache');

    global $wpdb;

    try {
        $patterns = array(
            '_transient_ag_llm_%',
            '_transient_timeout_ag_llm_%',
            '_transient_ag_llm_rl_%',
            '_transient_timeout_ag_llm_rl_%',
        );

        foreach ( $patterns as $like ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ) );
        }

        $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'cache_cleared'), admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;

    } catch (Throwable $e) {
        error_log('[AG LLM] Cache clear failed: ' . $e->getMessage());
        $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'cache_clear_fail', 'ag_llm_type' => 'error'), admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }
}
add_action('admin_post_ag_llm_clear_cache', 'ag_llm_handle_admin_clear_cache');
