<?php
/**
 * Plugin Name: Summar AI
 * Description: Power your content with AI. Ask questions about the current post/page and get answers grounded in its content.
 * Version: 0.1.15
 * Primary Branch: main
 * Author: Summar AI
 * Text Domain: summar-ai
 * Domain Path: /languages
 */

if ( ! defined('ABSPATH') ) { exit; }

require_once plugin_dir_path(__FILE__) . 'includes/openai.php';
require_once plugin_dir_path(__FILE__) . 'includes/query-logs.php';

// --- Plugin Update Checker (PUC) ---
$__puc_file = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
if (file_exists($__puc_file)) {
    require_once $__puc_file;

    add_action('plugins_loaded', function () {
        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/ysx21/summar-ai-releases/',
            __FILE__,
            'summar-ai'
        );

        $updateChecker->getVcsApi()->enableReleaseAssets();
    });
}


// Bump this to invalidate transients when prompt/output formatting rules change.
if ( ! defined('SUMMAR_AI_VERSION') ) {
    define('SUMMAR_AI_VERSION', '0.1.15');
}

if ( ! defined('AG_LLM_CACHE_VERSION') ) {
    define('AG_LLM_CACHE_VERSION', SUMMAR_AI_VERSION);
}
register_activation_hook(__FILE__, 'ag_llm_queries_install');

/* =========================
 * i18n (Admin language override)
 * ========================= */

function summar_ai_load_textdomain() {
    $domain = 'summar-ai';

    // Admin override only on real admin screens (not AJAX).
    if ( is_admin() && function_exists('wp_doing_ajax') && wp_doing_ajax() ) {
        load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
        return;
    }

    if ( is_admin() ) {
        $pref = (string) get_option('summar_ai_admin_language', 'auto');

        if ( $pref === 'en_US' ) {
            // English = source strings (no translation loaded)
            return;
        }

        if ( $pref === 'tr_TR' ) {
            $mofile = trailingslashit(plugin_dir_path(__FILE__)) . 'languages/' . $domain . '-tr_TR.mo';
            if ( file_exists($mofile) ) {
                load_textdomain($domain, $mofile);
                return;
            }
            // If file missing, fall back to auto
        }

        // Auto (or fallback)
        load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
        return;
    }

    // Front-end: always follow site locale automatically
    load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
}
// WP i18n flow friendly timing (avoids JIT warnings)
add_action('init', 'summar_ai_load_textdomain', 1);

/* =========================
 * Assets
 * ========================= */

function ag_llm_register_assets() {
    wp_register_style(
  'ag-llm-search',
  plugins_url('assets/ag-llm-search.css', __FILE__),
  array(),
  SUMMAR_AI_VERSION
);

wp_register_script(
  'summarai-frontend',
  plugins_url('assets/frontend.js', __FILE__),
  array(),
  SUMMAR_AI_VERSION,
  true
);
}
add_action('wp_enqueue_scripts', 'ag_llm_register_assets', 5);

function ag_llm_enqueue_frontend_script() {
    if ( ! wp_script_is('summarai-frontend', 'registered') ) {
        ag_llm_register_assets();
    }
    if ( ! wp_script_is('summarai-frontend', 'enqueued') ) {
        wp_enqueue_script('summarai-frontend');
    }
    wp_localize_script('summarai-frontend', 'summaraiFrontend', array(
        'restUrl' => esc_url_raw( rest_url('summarai/v1/generate') ),
        'nonce' => wp_create_nonce('wp_rest'),
    ));
}

function ag_llm_maybe_enqueue_assets() {
    if ( is_admin() ) return;

    global $wp_query;
    if ( empty($wp_query) || empty($wp_query->posts) ) return;

    foreach ( (array) $wp_query->posts as $p ) {
        if ( $p instanceof WP_Post ) {
            if ( has_shortcode($p->post_content, 'summar-ai') || has_shortcode($p->post_content, 'ag_llm_test') ) {
                wp_enqueue_style('ag-llm-search');
                ag_llm_enqueue_frontend_script();
                break;
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'ag_llm_maybe_enqueue_assets', 20);

/* =========================
 * Helpers
 * ========================= */

function ag_llm_get_api_key(): string {
    $opt = get_option('ag_openai_api_key');
    if ( ! empty($opt) ) return (string) $opt;
    return '';
}

function ag_llm_can_access_post( int $post_id ): bool {
    $post = get_post( $post_id );
    if ( ! $post ) return false;
    if ( post_password_required( $post ) ) return false;
    if ( $post->post_status === 'publish' ) return true;
    return is_user_logged_in() && current_user_can( 'read_post', $post_id );
}

function ag_llm_mb_lower( string $s ): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/* =========================
 * Entitlements (Free page cap)
 * ========================= */

function summar_ai_entitlement_base_url(): string {
    // Cloudflare Worker base URL (no trailing slash)
    return (string) apply_filters(
        'summar_ai_entitlement_base_url',
        'https://api.summar-ai.com'
    );
}

function summar_ai_fetch_entitlement_remote(): ?array {
    $base = rtrim(trim(summar_ai_entitlement_base_url()), '/');
    if ( $base === '' ) return null;

    $url = $base . '/v1/handshake';
    $payload = array('site_url' => home_url('/'));

    $res = wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 10,
        'body'    => wp_json_encode($payload),
    ));

    if ( is_wp_error($res) ) return null;

    $code = (int) wp_remote_retrieve_response_code($res);
    $raw  = (string) wp_remote_retrieve_body($res);

    if ( $code < 200 || $code >= 300 ) return null;

    $data = json_decode($raw, true);
    if ( ! is_array($data) || empty($data['ok']) ) return null;

    return $data;
}

function summar_ai_get_entitlement(): array {
    $cached = get_transient('summar_ai_entitlement');
    if ( is_array($cached) && isset($cached['page_cap']) ) return $cached;

    $remote = summar_ai_fetch_entitlement_remote();
    if ( is_array($remote) ) {
        $ent = array(
            'plan' => (string) ($remote['plan'] ?? 'free'),
            'page_cap' => (int) ($remote['page_cap'] ?? 10),
            'refresh_after_seconds' => (int) ($remote['refresh_after_seconds'] ?? 3600),
            'site_id' => (string) ($remote['site_id'] ?? ''),
            'host' => (string) ($remote['host'] ?? ''),
            'fetched_at' => time(),
        );

        update_option('summar_ai_entitlement_last', $ent, false);

        $ttl = (int) $ent['refresh_after_seconds'];
        if ( $ttl < 300 ) $ttl = 300;
        if ( $ttl > DAY_IN_SECONDS ) $ttl = DAY_IN_SECONDS;

        set_transient('summar_ai_entitlement', $ent, $ttl);
        return $ent;
    }

    // Fallback: last known, or “free 10”
    $last = get_option('summar_ai_entitlement_last', null);
    if ( is_array($last) && isset($last['page_cap']) ) {
        set_transient('summar_ai_entitlement', $last, 10 * MINUTE_IN_SECONDS);
        return $last;
    }

    return array(
        'plan' => 'free',
        'page_cap' => 10,
        'refresh_after_seconds' => 3600,
        'site_id' => '',
        'host' => '',
        'fetched_at' => time(),
        'fallback' => true,
    );
}

function summar_ai_is_premium(): bool {
    $ent = summar_ai_get_entitlement();
    return isset($ent['plan']) && $ent['plan'] === 'premium';
}

function summar_ai_page_cap(): int {
    $ent = summar_ai_get_entitlement();
    $cap = (int) ($ent['page_cap'] ?? 10);
    return max(1, $cap);
}

// Local whitelist: which post IDs are enabled for Free
function summar_ai_enabled_post_ids(): array {
    $ids = get_option('summar_ai_enabled_post_ids', array());
    if ( ! is_array($ids) ) $ids = array();

    // Preserve insertion order, remove duplicates/invalids
    $clean = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0 && ! isset($clean[$id])) {
            $clean[$id] = true;
        }
    }

    $out = array_values(array_map('intval', array_keys($clean)));

    // If the cap is lowered later, enforce it by trimming the stored allowlist (Free only).
    if ( ! summar_ai_is_premium() ) {
        $cap = summar_ai_page_cap();
        if (count($out) > $cap) {
            $out = array_slice($out, 0, $cap);
            update_option('summar_ai_enabled_post_ids', $out, false);
        }
    }

    return $out;
}

function summar_ai_is_post_enabled( int $post_id ): bool {
    if ( $post_id <= 0 ) return false;
    if ( summar_ai_is_premium() ) return true;
    return in_array($post_id, summar_ai_enabled_post_ids(), true);
}

function summar_ai_try_enable_post( int $post_id ): bool {
    if ( $post_id <= 0 ) return false;
    if ( summar_ai_is_premium() ) return true;

    $ids = summar_ai_enabled_post_ids();
    if ( in_array($post_id, $ids, true) ) return true;

    if ( count($ids) >= summar_ai_page_cap() ) return false;

    $ids[] = $post_id;
    update_option('summar_ai_enabled_post_ids', $ids, false);
    return true;
}


/**
 * Enhanced sanitizer (removes meta references to "text/context", normalizes "not in text")
 */
function ag_llm_sanitize_answer_style( string $answer ): string {
    $a = trim((string)$answer);
    if ($a === '') return $a;

    // Normalize line endings
    $a = str_replace(array("\r\n", "\r"), "\n", $a);

    // Normalize "not found" message (TR)
    if ( preg_match('/^(Bu\s+bilgi\s+)?(bu\s+)?(yaz[ıi]|içerik|icerik|metin|makale)\s*(de|da)?\s*yok\.?$/iu', $a) ) {
        return 'Bu bilgi yazıda yok.';
    }

    // Normalize "not found" message (EN)
    if ( preg_match('/^(That|This)\s+information\s+is\s+not\s+in\s+the\s+(text|article|content)\.?$/i', $a) ) {
        return 'This information is not in the text.';
    }

    // Remove leading meta intros (TR)
    $a = preg_replace('/^\s*(Bu\s+(yaz[ıi]|içerik|icerik|makale|sayfa)|Aşağıdaki\s+(metin|içerik)|Verilen\s+(bağlam|metin|icerik|içerik))\s*[:,\-\–—]?\s*/iu', '', $a, 1);

    // Remove leading meta intros (EN)
    $a = preg_replace('/^\s*(In\s+the\s+(text|article|context)\s*,?\s*)/i', '', $a, 1);
    $a = preg_replace('/^\s*(According\s+to\s+the\s+(text|article)\s*,?\s*)/i', '', $a, 1);
    $a = preg_replace('/^\s*(Based\s+on\s+the\s+(text|article|context)\s*,?\s*)/i', '', $a, 1);

    // Remove meta adverbs/phrases inside sentences (TR)
    $a = preg_replace('/\b(bağlamda|yaz[ıi]da|metinde|içerikte|makalede)\b\s*/iu', '', $a);

    // Remove meta phrases inside sentences (EN)
    $a = preg_replace('/\b(in\s+the\s+(text|article|context)|according\s+to\s+the\s+(text|article)|based\s+on\s+the\s+(text|article|context))\b\s*/i', '', $a);

    // Reduce academic/meta phrasing (TR)
    $a = preg_replace('/\b(bahsetmektedir|anlatmaktadır|ele\s+almaktadır)\b/iu', 'anlatır', $a);

    // If the model returned a single paragraph with multiple " - " segments,
    // convert it into: intro paragraph + bullet list + optional closing paragraph.
    if ( strpos($a, "\n") === false ) {
        $parts = preg_split('/\s+[-–—]\s+/u', $a);
        if ( is_array($parts) && count($parts) >= 3 ) {
            $intro = trim((string) array_shift($parts));
            $tail  = trim((string) end($parts));

            $closing = '';
            if ( $tail !== '' && preg_match('/^(Sonu(c|ç)|Özetle|Kısacası|Genel\s+olarak|Bu\s+nedenle|Bu\s+yüzden|Dolayısıyla|Son\s+olarak|Soğuk\s+iklimlerde)\b/iu', $tail) ) {
                $closing = array_pop($parts);
                $closing = trim((string) $closing);
            }

            if ( $intro !== '' ) {
                if ( ! preg_match('/[\.\!\?]\s*$/u', $intro) ) $intro .= '.';
            }

            $bullets = array();
            foreach ( $parts as $p ) {
                $p = trim((string) $p);
                if ( $p === '' ) continue;
                if ( ! preg_match('/[\.\!\?]\s*$/u', $p) ) $p .= '.';
                $bullets[] = $p;
            }

            if ( count($bullets) >= 2 ) {
                $out = $intro;
                $out .= "\n\n";
                foreach ( $bullets as $b ) {
                    $out .= "* {$b}\n";
                }
                $out = rtrim($out, "\n");

                if ( $closing !== '' ) {
                    if ( ! preg_match('/[\.\!\?]\s*$/u', $closing) ) $closing .= '.';
                    $out .= "\n\n" . $closing;
                }

                $a = $out;
            }
        }
    }

    // Normalize line-start bullets to "* "
    $a = preg_replace('/^[\t ]*[\-•\*]\s+/mu', '* ', $a);

    // Fix malformed bullet starts like "* ,text" or "* , text"
    $a = preg_replace('/^\*\s*,+\s*/mu', '* ', $a);

    // Cleanup whitespace without destroying newlines
    $a = preg_replace('/[ \t]{2,}/u', ' ', $a);
    $a = preg_replace('/[ \t]+\n/u', "\n", $a);
    $a = preg_replace('/\n[ \t]+/u', "\n", $a);
    $a = preg_replace("/\n{3,}/", "\n\n", $a);

    return trim($a);
}

/* =========================
 * Context
 * ========================= */

function ag_llm_build_context_from_post( int $post_id, int $max_chars = 12000 ): string {
    $post = get_post( $post_id );
    if ( ! $post ) return '';

    $content = (string) $post->post_content;
    $title   = get_the_title( $post );

    $content = strip_shortcodes( $content );
    $content = wp_strip_all_tags( $content, true );
    $content = preg_replace('/```[\s\S]*?```/u', ' ', $content);
    $content = preg_replace('/\s+/u', ' ', $content);
    $content = trim($content);

    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        if ( mb_strlen($content, 'UTF-8') > $max_chars ) {
            $content = mb_substr($content, 0, $max_chars, 'UTF-8') . ' …';
        }
    } else {
        if ( strlen($content) > $max_chars ) {
            $content = substr($content, 0, $max_chars) . ' …';
        }
    }

    return "TITLE:\n{$title}\n\nTEXT:\n{$content}";
}

/* =========================
 * LLM
 * ========================= */

function ag_llm_detect_question_language( string $question ): string {
    $q = trim( $question );
    if ( $q === '' ) {
        return 'en';
    }

    $q_lower = ag_llm_mb_lower( $q );
    $tr_score = 0;
    $en_score = 0;

    if ( preg_match( '/[çğıöşüİı]/u', $q ) ) {
        $tr_score += 3;
    }

    $tr_markers = array(
        ' ve ', ' için ', ' ile ', ' neden ', ' nasıl ', ' nedir ', ' mı ', ' mi ',
        ' bir ', ' bu ', ' şu ', ' daha ', ' var ', ' yok ', ' olabilir ',
    );
    foreach ( $tr_markers as $marker ) {
        if ( strpos( " {$q_lower} ", $marker ) !== false ) {
            $tr_score++;
        }
    }

    $en_markers = array(
        ' the ', ' and ', ' what ', ' why ', ' how ', ' is ', ' are ', ' can ',
        ' should ', ' could ', ' would ', ' does ', ' do ', ' in ', ' on ',
    );
    foreach ( $en_markers as $marker ) {
        if ( strpos( " {$q_lower} ", $marker ) !== false ) {
            $en_score++;
        }
    }

    return ( $tr_score > $en_score ) ? 'tr' : 'en';
}

function ag_llm_query_article( string $question, int $post_id ) {
    $api_key = ag_llm_get_api_key();
    if ( empty($api_key) ) {
        return new WP_Error('ag_llm_no_key', 'OpenAI API anahtarı bulunamadı.');
    }

    $context = ag_llm_build_context_from_post($post_id);
    if ( empty($context) ) {
        return new WP_Error('ag_llm_no_context', 'Yazı bağlamı üretilemedi.');
    }

    $question_language = ag_llm_detect_question_language( $question );
    $is_turkish = ( $question_language === 'tr' );

    $not_in_text_response = $is_turkish ? 'Bu bilgi yazıda yok.' : 'This information is not in the text.';
    $output_language = $is_turkish ? 'Türkçe' : 'English';

    if ( $is_turkish ) {
        $system_prompt = "Sen bir soru-cevap asistanısın. Yalnızca verilen referans metni kullanarak yanıt ver.

Zorunlu kurallar:
- Metin/makale/bağlam/kaynak ifadelerini anma (ör. 'bağlamda', 'yazıda', 'metinde', 'içerikte', 'makalede', 'in the text', 'according to the article'). Kullanıcıya doğrudan konuş.
- Asla bilgi uydurma veya dış bilgi ekleme. Gerekli bilgi metinde yoksa tam olarak şunu yaz: '{$not_in_text_response}'
- Çıktı dili ZORUNLU olarak {$output_language} olmalı.

Yanıt kalitesi:
- Doğal, akıcı ve konuşur gibi bir üslup kullan; robotik ve kalıp cümlelerden kaçın.
- Sonra referans metin tarafından açıkça desteklenen 2-5 somut noktayla gerekçelendir.
- Paragrafları kısa tut (1-2 cümle).

Çıktı biçimi (kesin):
- Kullanıcı özellikle istemedikçe bölüm başlığı ekleme.
- Liste daha anlaşılır olacaksa YALNIZCA şu biçimi kullan:
  1) Giriş cümlesini normal bir cümle olarak yaz ve nokta ile bitir.
  2) Boş bir satır ekle.
  3) Her madde kendi satırında olsun ve satırın başında '* ' (yıldız + boşluk) ile başlasın.
  4) (İsteğe bağlı) Sonuç cümlesi eklersen listeden SONRA ve arada boş satır olacak şekilde yaz.
- Paragraf içinde satır içi maddeleme veya tireyle ayrılmış liste yazma.
- Başka madde işareti sembolleri ('•', '-') veya numaralı liste kullanma.";
    } else {
        $system_prompt = "You are a Q&A assistant. Answer using ONLY the provided reference text.

Hard constraints:
- Do NOT mention the text/article/context/source (e.g., 'in the text', 'according to the article', 'based on the context', TR: 'bağlamda', 'yazıda', 'metinde', 'içerikte', 'makalede'). Speak directly to the user.
- Never invent facts or add outside knowledge. If the needed information is not present, respond with exactly: '{$not_in_text_response}'
- Output language MUST be {$output_language}.

Answer quality:
- Use a natural, conversational tone; avoid robotic or template-like phrasing.
- Then justify with 2–5 concrete points that are explicitly supported by the reference text.
- Keep paragraphs short (1–2 sentences).

Output format (strict):
- Do NOT add section titles/headings (e.g., 'Why needed?', 'Neden gerekli?') unless the user explicitly asks for headings.
- If a list improves clarity, you MUST use ONLY this format:
  1) Write the lead-in sentence as a normal sentence and end it with a period.
  2) Add a blank line.
  3) Each bullet must be on its own line and MUST start at the beginning of the line with '* ' (asterisk + space).
  4) (Optional) If you add a concluding sentence, put it AFTER the list, separated by a blank line.
- Never write inline bullets or dash-separated list items in a paragraph.
  Forbidden patterns: ': - item', '. - item - item', 'because: -', 'çünkü: -', ' - item - item'.
- Do NOT use other bullet symbols ('•', '-') and do NOT use numbered lists.";
    }

    $user_prompt = "REFERENCE:\n{$context}\n\nQUESTION:\n{$question}\n\nAnswer.";

    // Settings (admin)
    $model_opt = (string) get_option('ag_llm_model');
    $model_default = ! empty($model_opt) ? $model_opt : 'gpt-4.1-nano';

    $temp_opt = get_option('ag_llm_temperature');
    $temperature_default = (is_numeric($temp_opt)) ? (float) $temp_opt : 0.2;

    $max_tokens_opt = get_option('ag_llm_max_tokens');
    $max_tokens_default = (is_numeric($max_tokens_opt) && (int) $max_tokens_opt > 0) ? (int) $max_tokens_opt : 420;

    $body = array(
        'model' => apply_filters('ag_llm_model', $model_default, $post_id),
        'messages' => array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user',   'content' => $user_prompt),
        ),
        'temperature' => $temperature_default,
        'max_tokens'  => $max_tokens_default,
    );

    $result = summarai_openai_request( $body );
    if ( empty( $result['ok'] ) ) {
        $message = isset( $result['error'] ) ? (string) $result['error'] : 'LLM request failed.';
        return new WP_Error('ag_llm_request_failed', $message);
    }

    $data = $result['data'];
    if ( ! $data || empty( $data['choices'][0]['message']['content'] ) ) {
        return new WP_Error('ag_llm_bad_payload', 'LLM yanıtı beklenen formatta değil.');
    }

    $answer = trim( (string) $data['choices'][0]['message']['content'] );
    return ag_llm_sanitize_answer_style($answer);
}

/* =========================
 * REST API
 * ========================= */

function summarai_rest_can_generate(): bool {
    return true;
}

function summarai_rest_generate( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $prompt = '';
    $post_id = 0;
    if ( is_array($params) && isset($params['prompt']) ) {
        $prompt = sanitize_textarea_field( (string) $params['prompt'] );
    }
    if ( is_array($params) && isset($params['post_id']) ) {
        $post_id = (int) $params['post_id'];
    }

    if ( $post_id <= 0 || $prompt === '' ) {
        return rest_ensure_response(array(
            'ok' => false,
            'error' => 'Prompt and post ID are required.',
        ));
    }

    $max_q_len = (int) apply_filters('ag_llm_max_question_chars', 300);
    if ( function_exists('mb_strlen') ? (mb_strlen($prompt, 'UTF-8') > $max_q_len) : (strlen($prompt) > $max_q_len) ) {
        return rest_ensure_response(array(
            'ok' => false,
            'error' => 'Soru çok uzun. Lütfen daha kısa yaz.',
        ));
    }

    if ( ! ag_llm_can_access_post( $post_id ) ) {
        return rest_ensure_response(array(
            'ok' => false,
            'error' => 'Bu içerik için erişim yok.',
        ));
    }

    // Rate limit: empty => default 6, 0 disables
    $rl_opt = get_option('ag_llm_rate_limit_per_minute', '');
    $limit_default = ($rl_opt === '' || $rl_opt === null) ? 6 : (int) $rl_opt;
    $limit = (int) apply_filters('ag_llm_rate_limit_per_minute', $limit_default, $post_id);

    if ( $limit > 0 && ag_llm_rate_limit_hit($post_id, $limit) ) {
        return rest_ensure_response(array(
            'ok' => false,
            'error' => 'Çok fazla istek. Lütfen biraz sonra tekrar dene.',
        ));
    }

    // Telemetry: each request becomes a single event row (including cache hits).
    $telemetry_event_id = wp_generate_uuid4();
    $telemetry_occurred_at = gmdate('c');

    $modified = (string) get_post_modified_time('U', true, $post_id);
    $q_norm   = ag_llm_mb_lower(trim($prompt));
    $q_norm   = preg_replace('/\s+/u', ' ', $q_norm);
    $q_hash   = substr( md5($q_norm), 0, 16 );
    $model_opt = (string) get_option('ag_llm_model');
    $model_default = ! empty($model_opt) ? $model_opt : 'gpt-4.1-nano';
    $model = apply_filters('ag_llm_model', $model_default, $post_id);
    $model_hash = substr(md5((string) $model), 0, 10);
    $cache_key = 'ag_llm_' . $post_id . '_' . $modified . '_' . AG_LLM_CACHE_VERSION . '_' . $model_hash . '_' . $q_hash;

    // Cache TTL: empty => 6 hours, 0 disables
    $ttl_opt = get_option('ag_llm_cache_ttl_seconds', '');
    $ttl_default = ($ttl_opt === '' || $ttl_opt === null) ? (6 * HOUR_IN_SECONDS) : (int) $ttl_opt;
    $ttl = (int) apply_filters('ag_llm_cache_ttl_seconds', $ttl_default, $post_id);

    if ( $ttl > 0 ) {
        $cached = get_transient($cache_key);
        if ( is_string($cached) && $cached !== '' ) {
            summar_ai_telemetry_enqueue_event(array(
                'event_id' => $telemetry_event_id,
                'occurred_at' => $telemetry_occurred_at,
                'requests_total' => 1,
                'success_total' => 1,
                'error_total' => 0,
                'cache_hit_total' => 1,
                'latency_ms' => 0,
            ));
            // Log successful cache hits for REST responses.
            ag_llm_log_query($post_id, $prompt, $cached, (string) $model);
            return rest_ensure_response(array(
                'ok' => true,
                'answer' => $cached,
                'from_cache' => true,
            ));
        }
    }

    $latency_started_at = microtime(true);
    $res = ag_llm_query_article( $prompt, $post_id );
    $latency_ms = (int) round((microtime(true) - $latency_started_at) * 1000);

    if ( is_wp_error($res) ) {
        summar_ai_telemetry_enqueue_event(array(
            'event_id' => $telemetry_event_id,
            'occurred_at' => $telemetry_occurred_at,
            'requests_total' => 1,
            'success_total' => 0,
            'error_total' => 1,
            'cache_hit_total' => 0,
            'latency_ms' => max(0, (int) $latency_ms),
        ));
        $code = $res->get_error_code();
        $msg = ($code === 'ag_llm_quota')
            ? 'Şu anda LLM servisi kota nedeniyle yanıt veremiyor. Lütfen daha sonra tekrar deneyin.'
            : 'İstek şu anda tamamlanamadı. Lütfen tekrar deneyin.';
        return rest_ensure_response(array(
            'ok' => false,
            'error' => $msg,
        ));
    }

    if ( $ttl > 0 ) {
        set_transient($cache_key, $res, $ttl);
    }

    summar_ai_telemetry_enqueue_event(array(
        'event_id' => $telemetry_event_id,
        'occurred_at' => $telemetry_occurred_at,
        'requests_total' => 1,
        'success_total' => 1,
        'error_total' => 0,
        'cache_hit_total' => 0,
        'latency_ms' => max(0, (int) $latency_ms),
    ));

    // Log successful REST responses from the LLM.
    ag_llm_log_query($post_id, $prompt, $res, (string) $model);
    return rest_ensure_response(array(
        'ok' => true,
        'answer' => $res,
        'from_cache' => false,
    ));
}

function summarai_register_rest_routes() {
    register_rest_route('summarai/v1', '/generate', array(
        'methods' => 'POST',
        'callback' => 'summarai_rest_generate',
        'permission_callback' => 'summarai_rest_can_generate',
    ));

    // Telemetry flush endpoint (called by the central collector).
    register_rest_route('summarai/v1', '/telemetry/flush', array(
        'methods' => 'POST',
        'callback' => 'summar_ai_rest_telemetry_flush',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'summarai_register_rest_routes');

/* =========================
 * AJAX
 * ========================= */

add_action('wp_ajax_ag_llm_search', 'ag_llm_ajax_search');
add_action('wp_ajax_nopriv_ag_llm_search', 'ag_llm_ajax_search');
add_action('wp_ajax_ag_llm_get_nonce', 'ag_llm_ajax_get_nonce');
add_action('wp_ajax_nopriv_ag_llm_get_nonce', 'ag_llm_ajax_get_nonce');

function ag_llm_ajax_get_nonce() {
    nocache_headers();

    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error(array('message' => 'Method not allowed.'), 405);
    }

    $ref = wp_get_referer();
    if ( $ref ) {
        $ref_host  = (string) parse_url($ref, PHP_URL_HOST);
        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        if ( $ref_host && $home_host && strtolower($ref_host) !== strtolower($home_host) ) {
            wp_send_json_error(array('message' => 'Invalid origin.'), 403);
        }
    }

    $nonce = wp_create_nonce('ag_llm_nonce');
    wp_send_json_success(array('nonce' => $nonce));
}

function ag_llm_rate_limit_hit( int $post_id, int $limit_per_minute = 6 ): bool {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $ip_hash = substr( md5($ip), 0, 12 );
    $key = 'ag_llm_rl_' . $post_id . '_' . $ip_hash;

    $count = (int) get_transient($key);
    $count++;
    set_transient($key, $count, 60);

    return $count > $limit_per_minute;
}

function ag_llm_ajax_search() {
    nocache_headers();

    $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
    if ( ! wp_verify_nonce($nonce, 'ag_llm_nonce') ) {
        wp_send_json_error(array('message' => 'Geçersiz istek (nonce).'), 403);
    }

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $q       = isset($_POST['q']) ? sanitize_text_field( wp_unslash($_POST['q']) ) : '';

    if ( $post_id <= 0 || $q === '' ) {
        wp_send_json_error(array('message' => 'Eksik parametre.'), 400);
    }

    $max_q_len = (int) apply_filters('ag_llm_max_question_chars', 300);
    if ( function_exists('mb_strlen') ? (mb_strlen($q, 'UTF-8') > $max_q_len) : (strlen($q) > $max_q_len) ) {
        wp_send_json_error(array('message' => 'Soru çok uzun. Lütfen daha kısa yaz.'), 400);
    }

    if ( ! ag_llm_can_access_post( $post_id ) ) {
        wp_send_json_error(array('message' => 'Bu içerik için erişim yok.'), 403);
    }

    // Free page-cap gate (server-side)
    if ( ! summar_ai_is_post_enabled($post_id) ) {
        if ( current_user_can('edit_post', $post_id) ) {
            if ( ! summar_ai_try_enable_post($post_id) ) {
                wp_send_json_error(array('message' => 'Bu sayfada Free plan limiti nedeniyle devre dışı.'), 403);
            }
        } else {
            wp_send_json_error(array('message' => 'Bu sayfada Free plan limiti nedeniyle devre dışı.'), 403);
        }
    }


    // Rate limit: empty => default 6, 0 disables
    $rl_opt = get_option('ag_llm_rate_limit_per_minute', '');
    $limit_default = ($rl_opt === '' || $rl_opt === null) ? 6 : (int) $rl_opt;
    $limit = (int) apply_filters('ag_llm_rate_limit_per_minute', $limit_default, $post_id);

    if ( $limit > 0 && ag_llm_rate_limit_hit($post_id, $limit) ) {
        wp_send_json_error(array('message' => 'Çok fazla istek. Lütfen biraz sonra tekrar dene.'), 429);
    }

    $model_opt = (string) get_option('ag_llm_model');
    $model_default = ! empty($model_opt) ? $model_opt : 'gpt-4.1-nano';
    $model = apply_filters('ag_llm_model', $model_default, $post_id);

    $modified = (string) get_post_modified_time('U', true, $post_id);
    $q_norm   = ag_llm_mb_lower(trim($q));
    $q_norm   = preg_replace('/\s+/u', ' ', $q_norm);
    $q_hash   = substr( md5($q_norm), 0, 16 );
    $model_hash = substr(md5((string) $model), 0, 10);
    $cache_key = 'ag_llm_' . $post_id . '_' . $modified . '_' . AG_LLM_CACHE_VERSION . '_' . $model_hash . '_' . $q_hash;

    // Cache TTL: empty => 6 hours, 0 disables
    $ttl_opt = get_option('ag_llm_cache_ttl_seconds', '');
    $ttl_default = ($ttl_opt === '' || $ttl_opt === null) ? (6 * HOUR_IN_SECONDS) : (int) $ttl_opt;
    $ttl = (int) apply_filters('ag_llm_cache_ttl_seconds', $ttl_default, $post_id);

    if ( $ttl > 0 ) {
        $cached = get_transient($cache_key);
        if ( is_string($cached) && $cached !== '' ) {
            ag_llm_log_query($post_id, $q, $cached, (string) $model);
            wp_send_json_success(array('answer' => $cached, 'from_cache' => true));
        }
    }

    $res = ag_llm_query_article( $q, $post_id );

    if ( is_wp_error($res) ) {
        $code = $res->get_error_code();
        $msg = ($code === 'ag_llm_quota')
            ? 'Şu anda LLM servisi kota nedeniyle yanıt veremiyor. Lütfen daha sonra tekrar deneyin.'
            : 'İstek şu anda tamamlanamadı. Lütfen tekrar deneyin.';
        wp_send_json_error(array('message' => $msg), 200);
    }

    if ( $ttl > 0 ) set_transient($cache_key, $res, $ttl);

    ag_llm_log_query($post_id, $q, $res, (string) $model);
    wp_send_json_success(array('answer' => $res, 'from_cache' => false));
}

/* =========================
 * Shortcode UI
 * ========================= */

function ag_llm_search_box_shortcode(): string {
    $post_id = (int) get_the_ID();
    if ( ! $post_id ) return '';

    // Free page-cap gate: only enabled pages show the widget (Premium: unlimited)
    if ( ! summar_ai_is_post_enabled($post_id) ) {
        // If an editor/admin views the page, auto-enable it if there is capacity left
        if ( current_user_can('edit_post', $post_id) ) {
            if ( ! summar_ai_try_enable_post($post_id) ) {
                $cap  = summar_ai_page_cap();
                $used = count(summar_ai_enabled_post_ids());
                return '<div style="padding:10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;">'
                    . esc_html("Summar AI Free: {$used}/{$cap} sayfa limiti dolu. Başka bir sayfadan shortcode’u kaldırıp bunu ekleyebilirsin.")
                    . '</div>';
            }
        } else {
            // Visitors should not see anything
            return '';
        }
    }


    if ( ! wp_style_is('ag-llm-search', 'registered') ) ag_llm_register_assets();
    if ( ! wp_style_is('ag-llm-search', 'enqueued') ) wp_enqueue_style('ag-llm-search');
    ag_llm_enqueue_frontend_script();

    $fonts_handle = 'ag-llm-fonts';
    if ( ! wp_style_is($fonts_handle, 'registered') ) {
        wp_register_style(
            $fonts_handle,
            'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300..700&family=Hanken+Grotesk:wght@500&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap',
            [],
            null
        );
    }
    if ( ! wp_style_is($fonts_handle, 'enqueued') ) {
        wp_enqueue_style($fonts_handle);
    }

    if ( did_action('wp_head') ) {
        wp_print_styles($fonts_handle);
        wp_print_styles('ag-llm-search');
    }

    $grad_id     = 'agStarsGrad_' . $post_id . '_' . wp_rand(1000,9999);
    $instance_id = 'ag-llm-search-' . $post_id . '-' . wp_rand(1000,9999);

    ob_start(); ?>

<div id="<?php echo esc_attr($instance_id); ?>" class="ag-llm-search" data-post-id="<?php echo esc_attr($post_id); ?>">
  <div class="ag-llm-powered" aria-hidden="true">
    <span class="material-symbols-outlined">bolt</span>
    <span>Powered by Summar AI</span>
  </div>

  <div class="ag-llm-shell" role="group" aria-label="Yazı içi arama">
    <div class="ag-llm-inner">
      <div class="ag-llm-prefix" aria-hidden="true">
        <span class="ag-llm-stars">
          <svg viewBox="0 0 34 30" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true">
            <defs>
              <linearGradient id="<?php echo esc_attr($grad_id); ?>" x1="6" y1="2" x2="30" y2="28" gradientUnits="userSpaceOnUse">
                <stop stop-color="#135bec"/>
                <stop offset="1" stop-color="#135bec"/>
              </linearGradient>
            </defs>

            <path d="M 4.7 -0.5 L 6.2 3.3 L 10.0 4.8 L 6.2 6.3 L 4.7 10.1 L 3.2 6.3 L -0.6 4.8 L 3.2 3.3 Z"
                  fill="#135bec" opacity=".95"/>

            <path d="M 15.5 1.3 L 19.7 10.8 L 29.8 15.0 L 19.7 19.2 L 15.5 28.6 L 11.3 19.2 L 1.2 15.0 L 11.3 10.8 Z"
                  fill="url(#<?php echo esc_attr($grad_id); ?>)"/>

            <path d="M 29.9 18.7 L 31.4 22.5 L 35.2 24.0 L 31.4 25.5 L 29.9 29.3 L 28.4 25.5 L 24.6 24.0 L 28.4 22.5 Z"
                  fill="#135bec" opacity=".92"/>
          </svg>
        </span>
      </div>

      <?php
  $ag_lang_param = '';
  if (isset($_GET['lang'])) {
    $ag_lang_param = strtolower(sanitize_text_field(wp_unslash($_GET['lang']))); 
  }
  $ag_is_tr = ($ag_lang_param !== '' && strpos($ag_lang_param, 'tr') === 0); 
  $ag_placeholder = $ag_is_tr ? 'Yazıda AI ile bul' : 'Find with AI';
  $ag_btn_aria    = $ag_is_tr ? 'Gönder' : 'Send';
?>
      <input class="ag-llm-input" type="text" placeholder="<?php echo esc_attr($ag_placeholder); ?>" autocomplete="off">
    </div>

    <button class="ag-llm-btn" type="button" aria-label="<?php echo esc_attr($ag_btn_aria); ?>">
  <span class="ag-llm-btn-icon" aria-hidden="true">
    <span class="material-symbols-outlined">arrow_downward</span>
  </span>
  <span class="ag-llm-spinner" aria-hidden="true"></span>
</button>
  </div>

  <div class="ag-llm-status" role="status" aria-live="polite"></div>
  <div class="ag-llm-result" role="region" aria-live="polite"></div>
</div>

<script>
(function () {
  var root = document.getElementById('<?php echo esc_js($instance_id); ?>');
  if (!root) return;

  var input = root.querySelector('.ag-llm-input');
  var shell = root.querySelector('.ag-llm-shell');
  if (!input || !shell) return;

  var unlocked = false;

  input.tabIndex = -1;

  function lockFocus() {
    if (document.activeElement === input) input.blur();
  }

  function unlock() {
    if (unlocked) return;
    unlocked = true;
    input.tabIndex = 0;
  }

  function unlockAndFocus() {
    unlock();
    try { input.focus({ preventScroll: true }); }
    catch (e) { input.focus(); }
  }

  input.addEventListener('focus', function () {
    if (!unlocked) {
      lockFocus();
    }
  }, true);

  lockFocus();
  setTimeout(lockFocus, 0);
  setTimeout(lockFocus, 50);
  setTimeout(lockFocus, 200);

  function onPointerDown(e) {
    if (e.target && e.target.closest && e.target.closest('.ag-llm-btn')) return;

    if (!unlocked) {
      e.preventDefault();
      unlockAndFocus();
    }
  }

  shell.addEventListener('pointerdown', onPointerDown, { passive: false });
  shell.addEventListener('mousedown', onPointerDown, { passive: false });
  shell.addEventListener('touchstart', onPointerDown, { passive: false });
})();
</script>

<?php
    return (string) ob_get_clean();
}
add_shortcode('summar-ai', 'ag_llm_search_box_shortcode');

/* =========================
 * Admin Panel (Settings)
 * ========================= */

function ag_llm_admin_menu() {
    add_options_page(
        __('Summar AI', 'summar-ai'),
        __('Summar AI', 'summar-ai'),
        'manage_options',
        'ag-llm-settings',
        'ag_llm_render_settings_page'
    );
}
add_action('admin_menu', 'ag_llm_admin_menu');

function ag_llm_plugin_action_links( $links ) {
    $url = admin_url('options-general.php?page=ag-llm-settings');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'summar-ai') . '</a>';
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ag_llm_plugin_action_links');

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

function ag_llm_sanitize_cache_ttl_hours( $hours ) {
    if ( $hours === '' || $hours === null ) return '';
    if ( ! is_numeric($hours) ) return '';
    $h = (float) $hours;
    if ( $h < 0 ) $h = 0;
    // hours -> seconds
    $sec = (int) round($h * HOUR_IN_SECONDS);
    return (string) $sec;
}

function summar_ai_sanitize_admin_language( $v ) {
    $v = is_string($v) ? trim($v) : '';
    $allowed = array('auto', 'tr_TR', 'en_US');
    if ( ! in_array($v, $allowed, true) ) return 'auto';
    return $v;
}

function summar_ai_sanitize_telemetry_opt_in( $v ) {
    return ($v === '1' || $v === 1 || $v === true || $v === 'on') ? '1' : '0';
}

function summar_ai_update_nonautoload_option( $name, $value ) {
    if ( get_option($name, null) === null ) {
        add_option($name, $value, '', 'no');
        return;
    }
    update_option($name, $value);
}

function summar_ai_ensure_telemetry_install_id() {
    $install_id = (string) get_option('summar_ai_telemetry_install_id', '');
    if ( $install_id !== '' ) {
        return $install_id;
    }

    $install_id = wp_generate_uuid4();
    summar_ai_update_nonautoload_option('summar_ai_telemetry_install_id', (string) $install_id);
    return (string) $install_id;
}

function summar_ai_register_telemetry_install() {
    $token = (string) get_option('summar_ai_telemetry_token', '');
    if ( $token !== '' ) {
        return true;
    }

    $endpoint = 'https://stats.summar-ai.com/wp-json/summar-collector/v1/register';

    $attempt = 0;
    while ( $attempt < 2 ) {
        $install_id = summar_ai_ensure_telemetry_install_id();

        $res = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'install_id' => $install_id,
            )),
        ));

        if ( is_wp_error($res) ) {
            summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', $res->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode((string) $body, true);

        if ( $code === 409 && $attempt === 0 ) {
            summar_ai_update_nonautoload_option('summar_ai_telemetry_install_id', wp_generate_uuid4());
            $attempt++;
            continue;
        }

        if ( $code !== 200 || ! is_array($json) || empty($json['ok']) || empty($json['token']) ) {
            $err = 'register_failed';
            if ( is_array($json) && ! empty($json['error']) && is_string($json['error']) ) {
                $err = $json['error'];
            } elseif ( $code > 0 ) {
                $err = 'http_' . $code;
            }
            summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', $err);
            return false;
        }

        summar_ai_update_nonautoload_option('summar_ai_telemetry_token', (string) $json['token']);
        summar_ai_update_nonautoload_option('summar_ai_telemetry_registered_at', gmdate('c'));
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', '');
        return true;
    }

    summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', 'register_conflict_retry_failed');
    return false;
}

function summar_ai_schedule_telemetry_cron() {
    $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event('summar_ai_telemetry_cron') : null;
    if ( $event && isset($event->schedule) && $event->schedule !== 'hourly' ) {
        wp_clear_scheduled_hook('summar_ai_telemetry_cron');
        $event = null;
    }

    if ( ! $event && ! wp_next_scheduled('summar_ai_telemetry_cron') ) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'summar_ai_telemetry_cron');
    }
}

function summar_ai_clear_telemetry_cron() {
    wp_clear_scheduled_hook('summar_ai_telemetry_cron');
}

function summar_ai_get_telemetry_plugin_version() {
    static $version = null;
    if ( $version !== null ) {
        return $version;
    }

    // get_file_data may not be loaded on frontend/cron.
    if ( ! function_exists('get_file_data') && defined('ABSPATH') ) {
        @require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if ( function_exists('get_file_data') ) {
        $data = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
        $version = ! empty($data['Version']) ? (string) $data['Version'] : '';
    } else {
        // Fallback: avoid fatal; keep something meaningful.
        $version = defined('AG_LLM_CACHE_VERSION') ? (string) AG_LLM_CACHE_VERSION : '';
    }

    return $version;
}

function summar_ai_ensure_telemetry_site_tag() {
    $tag = (string) get_option('summar_ai_telemetry_site_tag', '');
    if ( $tag !== '' ) {
        return $tag;
    }

    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $suffix = '';
    for ( $i = 0; $i < 8; $i++ ) {
        $suffix .= $alphabet[ wp_rand(0, strlen($alphabet) - 1) ];
    }

    $tag = 'site-' . $suffix;
    summar_ai_update_nonautoload_option('summar_ai_telemetry_site_tag', $tag);
    return $tag;
}

/* =========================
 * TELEMETRY (per-request events)
 * ========================= */

function summar_ai_telemetry_hash_equals($a, $b) {
    if (function_exists('hash_equals')) {
        return hash_equals((string) $a, (string) $b);
    }
    $a = (string) $a;
    $b = (string) $b;
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function summar_ai_telemetry_collector_base_url() {
    return 'https://stats.summar-ai.com/wp-json/summar-collector/v1';
}

function summar_ai_get_telemetry_queue() {
    $raw = get_option('summar_ai_telemetry_queue', array());
    return is_array($raw) ? $raw : array();
}

function summar_ai_set_telemetry_queue($queue) {
    if (!is_array($queue)) $queue = array();
    summar_ai_update_nonautoload_option('summar_ai_telemetry_queue', $queue);
}

function summar_ai_telemetry_enqueue_event($event) {
    // Only collect when user opted in.
    if ( (string) get_option('summar_ai_telemetry_opt_in', '0') !== '1' ) {
        return;
    }

    if (!is_array($event)) {
        return;
    }

    $event_id = isset($event['event_id']) ? sanitize_text_field((string) $event['event_id']) : '';
    if ($event_id === '') {
        $event_id = wp_generate_uuid4();
    }

    $occurred_at = isset($event['occurred_at']) ? (string) $event['occurred_at'] : '';
    $occurred_ts = $occurred_at ? strtotime($occurred_at) : false;
    if (!$occurred_ts || $occurred_ts <= 0) {
        $occurred_at = gmdate('c');
    } else {
        $occurred_at = gmdate('c', (int) $occurred_ts);
    }

    $clean = array(
        'event_id' => $event_id,
        'occurred_at' => $occurred_at,
        'requests_total' => isset($event['requests_total']) ? max(0, (int) $event['requests_total']) : 1,
        'success_total' => isset($event['success_total']) ? max(0, (int) $event['success_total']) : 0,
        'error_total' => isset($event['error_total']) ? max(0, (int) $event['error_total']) : 0,
        'cache_hit_total' => isset($event['cache_hit_total']) ? max(0, (int) $event['cache_hit_total']) : 0,
        'latency_ms' => array_key_exists('latency_ms', $event) ? max(0, (int) $event['latency_ms']) : null,
    );

    $queue = summar_ai_get_telemetry_queue();
    $queue[] = $clean;

    // Prevent unbounded growth.
    $max = (int) apply_filters('summar_ai_telemetry_queue_max', 500);
    if ($max > 0 && count($queue) > $max) {
        $queue = array_slice($queue, -$max);
    }

    summar_ai_set_telemetry_queue($queue);
}

function summar_ai_telemetry_sign_headers($token, $raw_body) {
    $ts = time();
    $sig = hash_hmac('sha256', $ts . '.' . (string) $raw_body, (string) $token);
    return array(
        'X-Summar-Timestamp' => (string) $ts,
        'X-Summar-Signature' => $sig,
    );
}

function summar_ai_send_telemetry_meta() {
    if ( (string) get_option('summar_ai_telemetry_opt_in', '0') !== '1' ) {
        return false;
    }

    $install_id = (string) get_option('summar_ai_telemetry_install_id', '');
    $token = (string) get_option('summar_ai_telemetry_token', '');

    if ( $install_id === '' ) {
        $install_id = summar_ai_ensure_telemetry_install_id();
    }

    if ( $token === '' ) {
        summar_ai_register_telemetry_install();
        $token = (string) get_option('summar_ai_telemetry_token', '');
    }

    if ( $install_id === '' || $token === '' ) {
        return false;
    }

    $payload = array(
        'install_id' => $install_id,
        'site_tag' => summar_ai_ensure_telemetry_site_tag(),
        'site_url' => home_url('/'),
        'flush_url' => rest_url('summarai/v1/telemetry/flush'),
        'plugin_version' => summar_ai_get_telemetry_plugin_version(),
        'wp_version' => (string) get_bloginfo('version'),
        'php_version' => PHP_VERSION,
    );

    $raw_body = wp_json_encode($payload);
    if ( ! is_string($raw_body) || $raw_body === '' ) {
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', 'meta_json_encode_failed');
        return false;
    }

    $headers = array_merge(
        array('Content-Type' => 'application/json'),
        summar_ai_telemetry_sign_headers($token, $raw_body)
    );

    $res = wp_remote_post(summar_ai_telemetry_collector_base_url() . '/meta', array(
        'timeout' => 10,
        'headers' => $headers,
        'body' => $raw_body,
    ));

    if ( is_wp_error($res) ) {
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', $res->get_error_message());
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    if ( $code < 200 || $code >= 300 ) {
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', 'meta_http_' . $code);
        return false;
    }

    summar_ai_update_nonautoload_option('summar_ai_telemetry_last_meta_sent_at', gmdate('c'));
    return true;
}

function summar_ai_telemetry_flush_queue($source = 'cron') {
    if ( (string) get_option('summar_ai_telemetry_opt_in', '0') !== '1' ) {
        return array('ok' => true, 'status' => 'opted_out', 'flushed' => 0, 'remaining' => 0);
    }

    $install_id = (string) get_option('summar_ai_telemetry_install_id', '');
    if ( $install_id === '' ) {
        $install_id = summar_ai_ensure_telemetry_install_id();
    }

    $token = (string) get_option('summar_ai_telemetry_token', '');
    if ( $token === '' ) {
        summar_ai_register_telemetry_install();
        $token = (string) get_option('summar_ai_telemetry_token', '');
    }

    if ( $install_id === '' || $token === '' ) {
        return array('ok' => false, 'status' => 'not_registered', 'flushed' => 0, 'remaining' => count(summar_ai_get_telemetry_queue()));
    }

    // Send meta occasionally (or if never sent).
    $last_meta = (string) get_option('summar_ai_telemetry_last_meta_sent_at', '');
    $meta_ts = $last_meta ? strtotime($last_meta) : 0;
    if ( ! $meta_ts || (time() - (int) $meta_ts) > (6 * HOUR_IN_SECONDS) ) {
        summar_ai_send_telemetry_meta();
    }

    $queue = summar_ai_get_telemetry_queue();
    if ( empty($queue) ) {
        return array('ok' => true, 'status' => 'empty', 'flushed' => 0, 'remaining' => 0);
    }

    $sent = 0;
    $batch_size = (int) apply_filters('summar_ai_telemetry_flush_batch_size', 50);
    if ($batch_size < 1) $batch_size = 50;

    $max_batches = 20; // safety cap
    $batches = 0;
    $last_error = '';

    while ( ! empty($queue) && $batches < $max_batches ) {
        $batch = array_slice($queue, 0, $batch_size);
        if (empty($batch)) break;

        $payload = array(
            'install_id' => $install_id,
            'site_tag' => summar_ai_ensure_telemetry_site_tag(),
            'plugin_version' => summar_ai_get_telemetry_plugin_version(),
            'wp_version' => (string) get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'source' => (string) $source,
            'events' => array_values($batch),
        );

        $raw_body = wp_json_encode($payload);
        if ( ! is_string($raw_body) || $raw_body === '' ) {
            $last_error = 'checkin_json_encode_failed';
            break;
        }

        $headers = array_merge(
            array('Content-Type' => 'application/json'),
            summar_ai_telemetry_sign_headers($token, $raw_body)
        );

        $res = wp_remote_post(summar_ai_telemetry_collector_base_url() . '/checkin', array(
            'timeout' => 12,
            'headers' => $headers,
            'body' => $raw_body,
        ));

        if ( is_wp_error($res) ) {
            $last_error = $res->get_error_message();
            break;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ( $code < 200 || $code >= 300 ) {
            $last_error = 'checkin_http_' . $code;
            break;
        }

        $sent += count($batch);
        $queue = array_slice($queue, count($batch));
        summar_ai_set_telemetry_queue($queue);
        $batches++;
    }

    if ( $sent > 0 ) {
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_sent_at', gmdate('c'));
    }

    if ( $last_error !== '' ) {
        summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', $last_error);
        return array('ok' => false, 'status' => 'error', 'error' => $last_error, 'flushed' => $sent, 'remaining' => count($queue));
    }

    summar_ai_update_nonautoload_option('summar_ai_telemetry_last_error', '');
    return array('ok' => true, 'status' => 'ok', 'flushed' => $sent, 'remaining' => count($queue));
}

function summar_ai_telemetry_verify_inbound_request(WP_REST_Request $request, $raw_body) {
    $ts  = (string) $request->get_header('x-summar-timestamp');
    $sig = (string) $request->get_header('x-summar-signature');

    if ($ts === '' || $sig === '') {
        return new WP_Error('missing_headers', 'Missing signature headers', array('status' => 401));
    }
    if ( ! ctype_digit($ts) ) {
        return new WP_Error('bad_timestamp', 'Invalid timestamp', array('status' => 401));
    }

    $ts_i = (int) $ts;
    if ( abs(time() - $ts_i) > 600 ) {
        return new WP_Error('timestamp_skew', 'Timestamp out of range', array('status' => 401));
    }

    $token = (string) get_option('summar_ai_telemetry_token', '');
    if ( $token === '' ) {
        return new WP_Error('not_registered', 'Install not registered', array('status' => 401));
    }

    $msg = $ts . '.' . (string) $raw_body;
    $calc = hash_hmac('sha256', $msg, $token);

    if ( ! summar_ai_telemetry_hash_equals($calc, $sig) ) {
        return new WP_Error('bad_signature', 'Invalid signature', array('status' => 401));
    }

    return true;
}

function summar_ai_rest_telemetry_flush(WP_REST_Request $request) {
    $raw = (string) $request->get_body();
    $data = json_decode($raw, true);
    if ( ! is_array($data) ) {
        return new WP_Error('bad_json', 'Invalid JSON body', array('status' => 400));
    }

    $install_id_body = isset($data['install_id']) ? sanitize_text_field((string) $data['install_id']) : '';
    $install_id_opt  = (string) get_option('summar_ai_telemetry_install_id', '');
    if ( $install_id_opt !== '' && $install_id_body !== '' && $install_id_body !== $install_id_opt ) {
        return new WP_Error('bad_install_id', 'install_id mismatch', array('status' => 401));
    }

    $verified = summar_ai_telemetry_verify_inbound_request($request, $raw);
    if ( is_wp_error($verified) ) {
        return $verified;
    }

    // Rate limit / lock (avoid concurrent flush).
    if ( get_transient('summar_ai_telemetry_flush_lock') ) {
        $q = summar_ai_get_telemetry_queue();
        return array('ok' => true, 'status' => 'busy', 'flushed' => 0, 'remaining' => count($q));
    }

    set_transient('summar_ai_telemetry_flush_lock', '1', 55);
    $result = summar_ai_telemetry_flush_queue('remote');
    delete_transient('summar_ai_telemetry_flush_lock');

    return $result;
}

function summar_ai_telemetry_day_key() {
    return gmdate('Y-m-d');
}

function summar_ai_telemetry_daily_option_name( $day_key ) {
    return 'summar_ai_telemetry_daily_' . preg_replace('/[^0-9\-]/', '', (string) $day_key);
}

function summar_ai_get_telemetry_daily_metrics( $day_key = null ) {
    $day_key = $day_key ? (string) $day_key : summar_ai_telemetry_day_key();
    $opt_name = summar_ai_telemetry_daily_option_name($day_key);
    $raw = get_option($opt_name, array());
    $data = is_array($raw) ? $raw : array();

    return array(
        'requests_total' => isset($data['requests_total']) ? max(0, (int) $data['requests_total']) : 0,
        'success_total' => isset($data['success_total']) ? max(0, (int) $data['success_total']) : 0,
        'error_total' => isset($data['error_total']) ? max(0, (int) $data['error_total']) : 0,
        'cache_hit_total' => isset($data['cache_hit_total']) ? max(0, (int) $data['cache_hit_total']) : 0,
        'latency_sum_ms' => isset($data['latency_sum_ms']) ? max(0, (int) $data['latency_sum_ms']) : 0,
        'latency_count' => isset($data['latency_count']) ? max(0, (int) $data['latency_count']) : 0,
    );
}

function summar_ai_telemetry_increment_daily( $increments ) {
    if ( ! is_array($increments) || empty($increments) ) {
        return;
    }

    $day_key = summar_ai_telemetry_day_key();
    $metrics = summar_ai_get_telemetry_daily_metrics($day_key);

    foreach ( $increments as $k => $v ) {
        if ( ! isset($metrics[$k]) ) {
            continue;
        }
        $metrics[$k] += (int) $v;
        if ( $metrics[$k] < 0 ) {
            $metrics[$k] = 0;
        }
    }

    summar_ai_update_nonautoload_option(summar_ai_telemetry_daily_option_name($day_key), $metrics);
}

function summar_ai_send_telemetry_checkin() {
    // New telemetry model: per-request events flushed to the collector.
    $result = summar_ai_telemetry_flush_queue('cron');
    return (is_array($result) && ! empty($result['ok']));
}
add_action('summar_ai_telemetry_cron', 'summar_ai_send_telemetry_checkin');

function summar_ai_handle_telemetry_opt_in_add( $option, $value ) {
    if ( (string) $value !== '1' ) {
        return;
    }

    summar_ai_register_telemetry_install();
    if ( (string) get_option('summar_ai_telemetry_token', '') !== '' ) {
        summar_ai_schedule_telemetry_cron();
        summar_ai_send_telemetry_meta();
        summar_ai_send_telemetry_checkin(); // flush queue
    }
}
add_action('add_option_summar_ai_telemetry_opt_in', 'summar_ai_handle_telemetry_opt_in_add', 10, 2);

function summar_ai_handle_telemetry_opt_in_update( $old_value, $value ) {
    if ( (string) $value !== '1' ) {
        summar_ai_clear_telemetry_cron();
        // Opt-out: clear any locally queued telemetry.
        delete_option('summar_ai_telemetry_queue');
        delete_option('summar_ai_telemetry_last_meta_sent_at');
        return;
    }

    summar_ai_ensure_telemetry_install_id();

    if ( (string) get_option('summar_ai_telemetry_token', '') === '' ) {
        summar_ai_register_telemetry_install();
    }

    if ( (string) get_option('summar_ai_telemetry_token', '') !== '' ) {
        summar_ai_schedule_telemetry_cron();
        summar_ai_send_telemetry_meta();
        summar_ai_send_telemetry_checkin(); // flush queue
    }
}
add_action('update_option_summar_ai_telemetry_opt_in', 'summar_ai_handle_telemetry_opt_in_update', 10, 2);

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

    // stored as seconds (ag_llm_cache_ttl_seconds); input is hours
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

    register_setting('ag_llm_settings', 'summar_ai_admin_language', array(
        'type' => 'string',
        'sanitize_callback' => 'summar_ai_sanitize_admin_language',
        'default' => 'auto',
    ));

    register_setting('ag_llm_settings', 'summar_ai_telemetry_opt_in', array(
        'type' => 'string',
        'sanitize_callback' => 'summar_ai_sanitize_telemetry_opt_in',
        'default' => '0',
    ));
}
add_action('admin_init', 'ag_llm_register_settings');

function ag_llm_admin_notices() {
    if ( ! current_user_can('manage_options') ) return;

    // WP core "settings-updated"
    if ( isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true' && isset($_GET['page']) && $_GET['page'] === 'ag-llm-settings' ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'summar-ai') . '</p></div>';
        return;
    }

    if ( empty($_GET['ag_llm_notice']) ) return;

    $notice = sanitize_text_field( (string) $_GET['ag_llm_notice'] );
    $type   = (! empty($_GET['ag_llm_type']) && $_GET['ag_llm_type'] === 'error') ? 'error' : 'success';
    $msg    = '';

    if ( $notice === 'cache_cleared' ) $msg = __('Cache cleared.', 'summar-ai');
    if ( $notice === 'cache_clear_fail' ) $msg = __('Cache could not be cleared. (See PHP error log for details.)', 'summar-ai');

    if ( $notice === 'enabled_page_removed' ) $msg = __('Enabled page removed.', 'summar-ai');
    if ( $notice === 'enabled_pages_reset' ) $msg = __('Enabled pages reset.', 'summar-ai');


    if ( ! $msg ) return;

    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}
add_action('admin_notices', 'ag_llm_admin_notices');

function ag_llm_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';

    $api_key = (string) get_option('ag_openai_api_key');
    $model   = (string) get_option('ag_llm_model');
    $temp    = (string) get_option('ag_llm_temperature');
    $max_t   = (string) get_option('ag_llm_max_tokens');

    $ttl_raw = get_option('ag_llm_cache_ttl_seconds', '');
    $ttl_sec = ($ttl_raw === '' || $ttl_raw === null) ? '' : (int) $ttl_raw;
    $ttl_hours = ($ttl_sec !== '' && $ttl_sec >= 0) ? ($ttl_sec / HOUR_IN_SECONDS) : '';

    $rl = (string) get_option('ag_llm_rate_limit_per_minute');
    $admin_lang = (string) get_option('summar_ai_admin_language', 'auto');
    $telemetry_opt_in = (string) get_option('summar_ai_telemetry_opt_in', '0');
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Summar AI', 'summar-ai'); ?></h1>
      <?php ag_llm_render_settings_tabs($tab); ?>
      <?php if ( $tab === 'logs' ) { ag_llm_render_logs_tab(); return; } ?>
      <p><?php echo esc_html__('Manage your API key and core settings here.', 'summar-ai'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('ag_llm_settings'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ag_openai_api_key"><?php echo esc_html__('OpenAI API Key', 'summar-ai'); ?></label></th>
            <td>
              <input type="password" id="ag_openai_api_key" name="ag_openai_api_key" value="" class="regular-text" autocomplete="new-password" />
              <p class="description">
                <?php echo esc_html__('Enter a new API key. Leave blank to keep the saved key.', 'summar-ai'); ?>
                <?php if ( ! empty($api_key) ) : ?>
                  <?php echo esc_html__('(A key is currently saved.)', 'summar-ai'); ?>
                <?php endif; ?>
              </p>
              <label>
                <input type="checkbox" name="ag_llm_clear_api_key" value="1" />
                <?php echo esc_html__('Clear saved API key', 'summar-ai'); ?>
              </label>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_model"><?php echo esc_html__('Model', 'summar-ai'); ?></label></th>
            <td>
              <input type="text" id="ag_llm_model" name="ag_llm_model" value="<?php echo esc_attr($model); ?>" class="regular-text" placeholder="gpt-4.1-nano" />
              <p class="description"><?php echo esc_html__('Default if empty:', 'summar-ai'); ?> <code>gpt-4.1-nano</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_temperature"><?php echo esc_html__('Temperature', 'summar-ai'); ?></label></th>
            <td>
              <input type="number" step="0.1" min="0" max="2" id="ag_llm_temperature" name="ag_llm_temperature" value="<?php echo esc_attr($temp); ?>" class="small-text" placeholder="0.2" />
              <p class="description"><?php echo esc_html__('Default if empty:', 'summar-ai'); ?> <code>0.2</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_max_tokens"><?php echo esc_html__('Max tokens', 'summar-ai'); ?></label></th>
            <td>
              <input type="number" min="1" id="ag_llm_max_tokens" name="ag_llm_max_tokens" value="<?php echo esc_attr($max_t); ?>" class="small-text" placeholder="420" />
              <p class="description"><?php echo esc_html__('Default if empty:', 'summar-ai'); ?> <code>420</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_cache_ttl_seconds"><?php echo esc_html__('Cache TTL (hours)', 'summar-ai'); ?></label></th>
            <td>
              <input type="number" step="0.5" min="0" id="ag_llm_cache_ttl_seconds" name="ag_llm_cache_ttl_seconds" value="<?php echo esc_attr($ttl_hours); ?>" class="small-text" placeholder="6" />
              <p class="description"><?php echo esc_html__('Set to 0 to disable cache. Default if empty: 6 hours.', 'summar-ai'); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ag_llm_rate_limit_per_minute"><?php echo esc_html__('Rate limit (per minute)', 'summar-ai'); ?></label></th>
            <td>
              <input type="number" min="0" id="ag_llm_rate_limit_per_minute" name="ag_llm_rate_limit_per_minute" value="<?php echo esc_attr($rl); ?>" class="small-text" placeholder="6" />
              <p class="description"><?php echo esc_html__('Set to 0 to disable rate limiting. Default if empty: 6.', 'summar-ai'); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="summar_ai_admin_language"><?php echo esc_html__('Language', 'summar-ai'); ?></label></th>
            <td>
              <select id="summar_ai_admin_language" name="summar_ai_admin_language">
                <option value="auto" <?php selected($admin_lang, 'auto'); ?>><?php echo esc_html__('Auto (follow WordPress admin language)', 'summar-ai'); ?></option>
                <option value="en_US" <?php selected($admin_lang, 'en_US'); ?>><?php echo esc_html__('English', 'summar-ai'); ?></option>
                <option value="tr_TR" <?php selected($admin_lang, 'tr_TR'); ?>><?php echo esc_html__('Turkish', 'summar-ai'); ?></option>
              </select>
              <p class="description">
                <?php echo wp_kses_post( __( 'Auto uses the WordPress admin language. Turkish requires a <code>summar-ai-tr_TR.mo</code> file in <code>/languages</code>.', 'summar-ai' ) ); ?>
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="summar_ai_telemetry_opt_in"><?php echo esc_html__('Usage telemetry (opt-in)', 'summar-ai'); ?></label></th>
            <td>
              <input type="hidden" name="summar_ai_telemetry_opt_in" value="0" />
              <label>
                <input type="checkbox" id="summar_ai_telemetry_opt_in" name="summar_ai_telemetry_opt_in" value="1" <?php checked($telemetry_opt_in, '1'); ?> />
                <?php echo esc_html__('Share anonymous usage statistics to help improve the plugin.', 'summar-ai'); ?>
              </label>
              <p class="description"><?php echo esc_html__('Sends aggregated counts only (requests, success/error, cache hits, latency). No content is sent.', 'summar-ai'); ?></p>
            </td>
          </tr>
        </table>

        <?php submit_button( __('Save Changes', 'summar-ai') ); ?>
      </form>

      <hr />

      <h2><?php echo esc_html__('Quick Actions', 'summar-ai'); ?></h2>

      <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('ag_llm_clear_cache'); ?>
          <input type="hidden" name="action" value="ag_llm_clear_cache" />
          <?php submit_button( __('Clear Cache', 'summar-ai'), 'delete', 'submit', false ); ?>
          <p class="description" style="margin-top:8px;"><?php echo esc_html__('Clears this plugin’s transient cache and rate-limit records.', 'summar-ai'); ?></p>
        </form>
      </div>

      <hr />

      <h2><?php echo esc_html__('Usage', 'summar-ai'); ?></h2>
      <p><?php echo esc_html__('Add this shortcode to any post/page:', 'summar-ai'); ?></p>
      <p><code>[summar-ai]</code></p>
    

  <?php

    $ent = summar_ai_get_entitlement();
    $cap  = summar_ai_page_cap();
    $enabled_ids = summar_ai_enabled_post_ids();
    $used = count($enabled_ids);
  ?>

  <div style="margin-top:16px; padding:12px; border:1px solid #dcdcde; border-radius:8px; background:#fff;">
  <p style="margin:0 0 8px;">
    <strong><?php echo esc_html__('Enabled pages', 'summar-ai'); ?>:</strong> <?php echo esc_html($used . '/' . $cap); ?>
  </p>
  <p class="description" style="margin:0;">
    <?php echo esc_html__('Enabled pages are the posts/pages where the shortcode is allowed to run.', 'summar-ai'); ?>
  </p>
</div>
<h3 style="margin-top:18px;"><?php echo esc_html__('Enabled pages', 'summar-ai'); ?></h3>

  <?php if ( empty($enabled_ids) ): ?>
    <p class="description"><?php echo esc_html__('No pages enabled yet. Visit a post/page with the shortcode as an editor/admin to enable it (until the cap is reached).', 'summar-ai'); ?></p>
  <?php else: ?>
    <table class="widefat striped" style="max-width: 900px;">
      <thead>
        <tr>
          <th><?php echo esc_html__('Title', 'summar-ai'); ?></th>
          <th style="width:130px;"><?php echo esc_html__('Post ID', 'summar-ai'); ?></th>
          <th style="width:160px;"><?php echo esc_html__('Shortcode', 'summar-ai'); ?></th>
          <th style="width:160px;"><?php echo esc_html__('Action', 'summar-ai'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $enabled_ids as $pid ): ?>
          <?php
            $p = get_post($pid);
            $title = $p ? get_the_title($p) : __('(missing)', 'summar-ai');
            $edit_link = $p ? get_edit_post_link($pid) : '';
            $has_sc = ($p && has_shortcode((string)$p->post_content, 'summar-ai'));
          ?>
          <tr>
            <td>
              <?php if ( $edit_link ): ?>
                <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($title); ?></a>
              <?php else: ?>
                <?php echo esc_html($title); ?>
              <?php endif; ?>
            </td>
            <td><code><?php echo esc_html((string)$pid); ?></code></td>
            <td>
              <?php if ( $has_sc ): ?>
                <span style="color:#1e8e3e; font-weight:600;"><?php echo esc_html__('Present', 'summar-ai'); ?></span>
              <?php else: ?>
                <span style="color:#b32d2e; font-weight:600;"><?php echo esc_html__('Not found', 'summar-ai'); ?></span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <?php wp_nonce_field('summar_ai_enabled_pages'); ?>
                <input type="hidden" name="action" value="summar_ai_remove_enabled_page" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string)$pid); ?>" />
                <?php submit_button( __('Remove', 'summar-ai'), 'secondary', 'submit', false ); ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:12px;">
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Reset enabled pages? This will remove all enabled pages and free up slots.', 'summar-ai')); ?>');" style="display:inline-block;">
        <?php wp_nonce_field('summar_ai_enabled_pages'); ?>
        <input type="hidden" name="action" value="summar_ai_reset_enabled_pages" />
        <?php submit_button( __('Reset enabled pages', 'summar-ai'), 'delete', 'submit', false ); ?>
      </form>
    </div>
  <?php endif; ?>

</div>
    <?php
}

/**
 * Admin action: Clear Cache (transients)
 * Fix: LIKE patterns must escape '_' (underscore is a wildcard in SQL LIKE).
 */
function ag_llm_handle_admin_clear_cache() {
    if ( ! current_user_can('manage_options') ) wp_die( esc_html__('Unauthorized.', 'summar-ai') );
    check_admin_referer('ag_llm_clear_cache');

    global $wpdb;
    try {
        $prefixes = array(
            '_transient_ag_llm_',
            '_transient_timeout_ag_llm_',
            '_transient_ag_llm_rl_',
            '_transient_timeout_ag_llm_rl_',
        );

        foreach ( $prefixes as $prefix ) {
            $like = $wpdb->esc_like($prefix) . '%';
            $sql  = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s ESCAPE '\\\\'";
            $wpdb->query( $wpdb->prepare($sql, $like) );
        }

        $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'cache_cleared'), admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    } catch (Throwable $e) {
        error_log('[Summar AI] Cache clear failed: ' . $e->getMessage());
        $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'cache_clear_fail', 'ag_llm_type' => 'error'), admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }
}
add_action('admin_post_ag_llm_clear_cache', 'ag_llm_handle_admin_clear_cache');

function summar_ai_handle_remove_enabled_page() {
    if ( ! current_user_can('manage_options') ) wp_die( esc_html__('Unauthorized.', 'summar-ai') );
    check_admin_referer('summar_ai_enabled_pages');

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if ( $post_id > 0 ) {
        $ids = summar_ai_enabled_post_ids();
        $ids = array_values(array_filter($ids, static function($id) use ($post_id) {
            return (int) $id !== $post_id;
        }));
        update_option('summar_ai_enabled_post_ids', $ids, false);
    }

    $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'enabled_page_removed'), admin_url('options-general.php'));
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_summar_ai_remove_enabled_page', 'summar_ai_handle_remove_enabled_page');

function summar_ai_handle_reset_enabled_pages() {
    if ( ! current_user_can('manage_options') ) wp_die( esc_html__('Unauthorized.', 'summar-ai') );
    check_admin_referer('summar_ai_enabled_pages');

    update_option('summar_ai_enabled_post_ids', array(), false);

    $url = add_query_arg(array('page' => 'ag-llm-settings', 'ag_llm_notice' => 'enabled_pages_reset'), admin_url('options-general.php'));
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_summar_ai_reset_enabled_pages', 'summar_ai_handle_reset_enabled_pages');
