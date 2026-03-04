<?php

if ( ! defined('ABSPATH') ) { exit; }

function ag_llm_queries_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'ag_llm_queries';
}

function ag_llm_queries_install(): void {
    global $wpdb;

    $table = ag_llm_queries_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        question LONGTEXT NOT NULL,
        answer LONGTEXT NOT NULL,
        model VARCHAR(100) NOT NULL,
        PRIMARY KEY  (id),
        KEY post_id_created (post_id, created_at),
        KEY created_at (created_at),
        KEY model (model)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function ag_llm_log_query( int $post_id, string $question, string $answer, string $model ): void {
    global $wpdb;

    $created_at = current_time('mysql', true);

    $question = str_replace("\0", '', $question);
    $answer = str_replace("\0", '', $answer);

    $question = str_replace(array("\r\n", "\r"), "\n", $question);
    $answer = str_replace(array("\r\n", "\r"), "\n", $answer);

    $wpdb->insert(
        ag_llm_queries_table_name(),
        array(
            'created_at' => $created_at,
            'post_id' => $post_id,
            'question' => $question,
            'answer' => $answer,
            'model' => $model,
        ),
        array('%s', '%d', '%s', '%s', '%s')
    );
}

function ag_llm_render_settings_tabs( string $active_tab ): void {
    $tabs = array(
        'settings' => __('Settings', 'summar-ai'),
        'logs' => __('Logs', 'summar-ai'),
    );
    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $tab => $label ) {
        $class = ($active_tab === $tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = add_query_arg(
            array(
                'page' => 'ag-llm-settings',
                'tab' => $tab,
            ),
            admin_url('options-general.php')
        );
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
}

function ag_llm_sanitize_log_date( string $date ): string {
    $date = trim($date);
    if ( $date === '' ) {
        return '';
    }
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
        return '';
    }
    return $date;
}

function ag_llm_truncate_log_text( string $text, int $limit = 200 ): string {
    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        if ( mb_strlen($text, 'UTF-8') <= $limit ) {
            return $text;
        }
        return mb_substr($text, 0, $limit, 'UTF-8') . '…';
    }

    if ( strlen($text) <= $limit ) {
        return $text;
    }

    return substr($text, 0, $limit) . '…';
}

function ag_llm_logs_admin_notice(): void {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    if ( empty($_GET['page']) || $_GET['page'] !== 'ag-llm-settings' ) {
        return;
    }

    if ( empty($_GET['ag_llm_log_notice']) ) {
        return;
    }

    $notice = sanitize_text_field( (string) $_GET['ag_llm_log_notice'] );
    if ( $notice !== 'cleared' ) {
        return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Logs cleared.', 'summar-ai') . '</p></div>';
}
add_action('admin_notices', 'ag_llm_logs_admin_notice');

function ag_llm_handle_clear_logs(): void {
    if ( ! current_user_can('manage_options') ) {
        wp_die( esc_html__('Unauthorized.', 'summar-ai') );
    }

    check_admin_referer('ag_llm_clear_logs');

    global $wpdb;
    $table = ag_llm_queries_table_name();
    $wpdb->query("DELETE FROM {$table}");

    $url = add_query_arg(
        array(
            'page' => 'ag-llm-settings',
            'tab' => 'logs',
            'ag_llm_log_notice' => 'cleared',
        ),
        admin_url('options-general.php')
    );
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_ag_llm_clear_logs', 'ag_llm_handle_clear_logs');

function ag_llm_render_logs_tab(): void {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    global $wpdb;
    $table = ag_llm_queries_table_name();

    $view_id = isset($_GET['view_id']) ? absint($_GET['view_id']) : 0;
    $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
    $model = isset($_GET['model']) ? sanitize_text_field( wp_unslash($_GET['model']) ) : '';
    $search = isset($_GET['search']) ? sanitize_text_field( wp_unslash($_GET['search']) ) : '';
    $date_from = isset($_GET['date_from']) ? ag_llm_sanitize_log_date( wp_unslash($_GET['date_from']) ) : '';
    $date_to = isset($_GET['date_to']) ? ag_llm_sanitize_log_date( wp_unslash($_GET['date_to']) ) : '';

    $models = $wpdb->get_col("SELECT DISTINCT model FROM {$table} ORDER BY model ASC");
    if ( ! is_array($models) ) {
        $models = array();
    }

    if ( $view_id > 0 ) {
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $view_id),
            ARRAY_A
        );

        $back_url = remove_query_arg('view_id');
        echo '<h2>' . esc_html__('Log Details', 'summar-ai') . '</h2>';
        echo '<p><a href="' . esc_url($back_url) . '">' . esc_html__('Back to logs', 'summar-ai') . '</a></p>';

        if ( empty($row) ) {
            echo '<p>' . esc_html__('Log entry not found.', 'summar-ai') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<tbody>';
        echo '<tr><th>' . esc_html__('ID', 'summar-ai') . '</th><td>' . esc_html((string) $row['id']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Created At', 'summar-ai') . '</th><td>' . esc_html((string) $row['created_at']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Post ID', 'summar-ai') . '</th><td>' . esc_html((string) $row['post_id']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Model', 'summar-ai') . '</th><td>' . esc_html((string) $row['model']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Question', 'summar-ai') . '</th><td>' . nl2br(esc_html((string) $row['question'])) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Answer', 'summar-ai') . '</th><td>' . nl2br(esc_html((string) $row['answer'])) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';

        return;
    }

    $where = array();
    $params = array();

    if ( $post_id > 0 ) {
        $where[] = 'post_id = %d';
        $params[] = $post_id;
    }

    if ( $model !== '' ) {
        $where[] = 'model = %s';
        $params[] = $model;
    }

    if ( $search !== '' ) {
        $where[] = 'question LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }

    if ( $date_from !== '' ) {
        $where[] = 'created_at >= %s';
        $params[] = $date_from . ' 00:00:00';
    }

    if ( $date_to !== '' ) {
        $where[] = 'created_at <= %s';
        $params[] = $date_to . ' 23:59:59';
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

    $query_sql = "SELECT id, created_at, post_id, question, answer, model FROM {$table} {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
    $query_params = $params;
    $query_params[] = $per_page;
    $query_params[] = $offset;

    $rows = $params
        ? $wpdb->get_results($wpdb->prepare($query_sql, $query_params), ARRAY_A)
        : $wpdb->get_results($wpdb->prepare($query_sql, $per_page, $offset), ARRAY_A);

    echo '<h2>' . esc_html__('Logs', 'summar-ai') . '</h2>';

    echo '<form method="get" action="' . esc_url(admin_url('options-general.php')) . '">';
    echo '<input type="hidden" name="page" value="ag-llm-settings" />';
    echo '<input type="hidden" name="tab" value="logs" />';

    echo '<div style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:12px;">';

    echo '<div>';
    echo '<label for="ag-llm-log-post-id" style="display:block;">' . esc_html__('Post ID', 'summar-ai') . '</label>';
    echo '<input type="number" min="0" name="post_id" id="ag-llm-log-post-id" value="' . esc_attr($post_id ?: '') . '" class="small-text" />';
    echo '</div>';

    echo '<div>';
    echo '<label for="ag-llm-log-model" style="display:block;">' . esc_html__('Model', 'summar-ai') . '</label>';
    echo '<select name="model" id="ag-llm-log-model">';
    echo '<option value="">' . esc_html__('All models', 'summar-ai') . '</option>';
    foreach ( $models as $model_option ) {
        echo '<option value="' . esc_attr($model_option) . '" ' . selected($model, $model_option, false) . '>' . esc_html($model_option) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div>';
    echo '<label for="ag-llm-log-search" style="display:block;">' . esc_html__('Question contains', 'summar-ai') . '</label>';
    echo '<input type="text" name="search" id="ag-llm-log-search" value="' . esc_attr($search) . '" class="regular-text" />';
    echo '</div>';

    echo '<div>';
    echo '<label for="ag-llm-log-date-from" style="display:block;">' . esc_html__('Date from (UTC)', 'summar-ai') . '</label>';
    echo '<input type="date" name="date_from" id="ag-llm-log-date-from" value="' . esc_attr($date_from) . '" />';
    echo '</div>';

    echo '<div>';
    echo '<label for="ag-llm-log-date-to" style="display:block;">' . esc_html__('Date to (UTC)', 'summar-ai') . '</label>';
    echo '<input type="date" name="date_to" id="ag-llm-log-date-to" value="' . esc_attr($date_to) . '" />';
    echo '</div>';

    echo '<div style="align-self:flex-end;">';
    submit_button( __('Filter', 'summar-ai'), 'secondary', '', false );
    echo '</div>';

    echo '</div>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ag_llm_clear_logs');
    echo '<input type="hidden" name="action" value="ag_llm_clear_logs" />';
    submit_button( __('Clear Logs', 'summar-ai'), 'delete', '', false );
    echo '</form>';

    if ( empty($rows) ) {
        echo '<p>' . esc_html__('No logs found.', 'summar-ai') . '</p>';
        return;
    }

    echo '<table class="widefat striped" style="margin-top:16px;">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('ID', 'summar-ai') . '</th>';
    echo '<th>' . esc_html__('Created At (UTC)', 'summar-ai') . '</th>';
    echo '<th>' . esc_html__('Post ID', 'summar-ai') . '</th>';
    echo '<th>' . esc_html__('Question', 'summar-ai') . '</th>';
    echo '<th>' . esc_html__('Answer', 'summar-ai') . '</th>';
    echo '<th>' . esc_html__('Model', 'summar-ai') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ( $rows as $row ) {
        $view_url = add_query_arg(
            array(
                'page' => 'ag-llm-settings',
                'tab' => 'logs',
                'view_id' => (int) $row['id'],
                'post_id' => $post_id ?: null,
                'model' => $model ?: null,
                'search' => $search ?: null,
                'date_from' => $date_from ?: null,
                'date_to' => $date_to ?: null,
                'paged' => $paged > 1 ? $paged : null,
            ),
            admin_url('options-general.php')
        );
        $question_preview = ag_llm_truncate_log_text((string) $row['question']);
        $answer_preview = ag_llm_truncate_log_text((string) $row['answer']);

        echo '<tr>';
        echo '<td>' . esc_html((string) $row['id']) . '<div class="row-actions"><span class="view"><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'summar-ai') . '</a></span></div></td>';
        echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
        echo '<td>' . esc_html((string) $row['post_id']) . '</td>';
        echo '<td>' . esc_html($question_preview) . '</td>';
        echo '<td>' . esc_html($answer_preview) . '</td>';
        echo '<td>' . esc_html((string) $row['model']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    $total_pages = (int) ceil($total / $per_page);
    if ( $total_pages > 1 ) {
        $pagination_base = add_query_arg(
            array(
                'page' => 'ag-llm-settings',
                'tab' => 'logs',
                'post_id' => $post_id ?: null,
                'model' => $model ?: null,
                'search' => $search ?: null,
                'date_from' => $date_from ?: null,
                'date_to' => $date_to ?: null,
                'paged' => '%#%',
            ),
            admin_url('options-general.php')
        );
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo wp_kses_post(paginate_links(array(
            'base' => $pagination_base,
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => __('« Prev', 'summar-ai'),
            'next_text' => __('Next »', 'summar-ai'),
            'type' => 'list',
        )));
        echo '</div></div>';
    }
}
