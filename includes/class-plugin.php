<?php

namespace AutoReviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    const CPT            = 'auto_review';
    const OPTION_SETTINGS = 'auto_reviews_settings';
    const CRON_HOOK       = 'auto_reviews_monthly_publish';

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    protected static $instance = null;

    /**
     * Get the plugin instance.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post_' . self::CPT, [ $this, 'save_review_meta' ] );
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );

        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'maybe_reschedule_cron' ] );
        add_action( 'update_option_' . self::OPTION_SETTINGS, [ $this, 'schedule_cron' ] );

        add_filter( 'cron_schedules', [ $this, 'register_monthly_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'cron_publish_from_queue' ] );

        // Shortcodes / front.
        add_shortcode( 'auto_reviews_count', [ $this, 'shortcode_reviews_count' ] );
        add_shortcode( 'auto_reviews_schema', [ $this, 'shortcode_reviews_schema' ] );
        add_shortcode( 'auto_reviews_list', [ $this, 'shortcode_reviews_list' ] );

        // We don't output a separate block in <head> — aggregateRating is injected via auto_reviews_aggregate_rating_schema.
        add_filter( 'auto_reviews_aggregate_rating_schema', [ $this, 'get_aggregate_rating_for_schema' ] );

        add_action( 'wp_ajax_auto_reviews_publish_now', [ $this, 'ajax_publish_now' ] );

        add_filter( 'get_comment_date', [ $this, 'hide_comment_date' ], 10, 3 );
        add_filter( 'get_comment_time', [ $this, 'hide_comment_time' ], 10, 3 );
        add_filter( 'comment_date', [ $this, 'hide_comment_date_output' ], 10, 3 );
        add_filter( 'comment_time', [ $this, 'hide_comment_time_output' ], 10, 2 );

        add_action( 'admin_head', [ $this, 'add_admin_css' ] );
        add_action( 'wp_head', [ $this, 'add_frontend_css' ] );
        add_filter( 'comment_text', [ $this, 'add_rating_stars_to_comment' ], 10, 2 );
    }

    /**
     * Register custom post type for reviews.
     */
    public function register_cpt() {
        $labels = [
            'name'               => __( 'Auto Reviews', 'auto-reviews' ),
            'singular_name'      => __( 'Auto Review', 'auto-reviews' ),
            'add_new'            => __( 'Добавить отзыв', 'auto-reviews' ),
            'add_new_item'       => __( 'Добавить новый отзыв', 'auto-reviews' ),
            'edit_item'          => __( 'Редактировать отзыв', 'auto-reviews' ),
            'new_item'           => __( 'Новый отзыв', 'auto-reviews' ),
            'view_item'          => __( 'Просмотр отзыва', 'auto-reviews' ),
            'search_items'       => __( 'Искать отзывы', 'auto-reviews' ),
            'not_found'          => __( 'Отзывов не найдено', 'auto-reviews' ),
            'not_found_in_trash' => __( 'В корзине отзывов не найдено', 'auto-reviews' ),
            'menu_name'          => __( 'Auto Reviews', 'auto-reviews' ),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'supports'           => [ 'title', 'editor' ],
            'has_archive'        => false,
            'rewrite'            => false,
        ];

        register_post_type( self::CPT, $args );
    }

    /**
     * Register meta boxes for review details.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'auto_reviews_details',
            __( 'Данные отзыва', 'auto-reviews' ),
            [ $this, 'render_meta_box' ],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'auto_reviews_save_meta', 'auto_reviews_meta_nonce' );

        $rating     = get_post_meta( $post->ID, '_auto_reviews_rating', true );
        $source     = get_post_meta( $post->ID, '_auto_reviews_source', true );
        $queued     = get_post_meta( $post->ID, '_auto_reviews_queued', true );
        $author     = get_post_meta( $post->ID, '_auto_reviews_author', true );
        $author_url = get_post_meta( $post->ID, '_auto_reviews_author_url', true );

        ?>
        <p>
            <label for="auto_reviews_author"><?php esc_html_e( 'Имя автора', 'auto-reviews' ); ?></label><br>
            <input type="text" id="auto_reviews_author" name="auto_reviews_author" value="<?php echo esc_attr( $author ); ?>" class="regular-text">
        </p>
        <p>
            <label for="auto_reviews_author_url"><?php esc_html_e( 'URL автора (опционально)', 'auto-reviews' ); ?></label><br>
            <input type="url" id="auto_reviews_author_url" name="auto_reviews_author_url" value="<?php echo esc_attr( $author_url ); ?>" class="regular-text">
        </p>
        <p>
            <label for="auto_reviews_rating"><?php esc_html_e( 'Оценка (1-5)', 'auto-reviews' ); ?></label><br>
            <input type="number" min="1" max="5" id="auto_reviews_rating" name="auto_reviews_rating" value="<?php echo esc_attr( $rating ); ?>">
        </p>
        <p>
            <label for="auto_reviews_source"><?php esc_html_e( 'Источник (manual / sheet / gpt)', 'auto-reviews' ); ?></label><br>
            <input type="text" id="auto_reviews_source" name="auto_reviews_source" value="<?php echo esc_attr( $source ); ?>" class="regular-text">
        </p>
        <p>
            <label>
                <input type="checkbox" name="auto_reviews_queued" value="1" <?php checked( $queued, '1' ); ?>>
                <?php esc_html_e( 'Держать в очереди отложенной публикации', 'auto-reviews' ); ?>
            </label>
        </p>
        <?php
    }

    public function save_review_meta( $post_id ) {
        if ( ! isset( $_POST['auto_reviews_meta_nonce'] ) || ! wp_verify_nonce( $_POST['auto_reviews_meta_nonce'], 'auto_reviews_save_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( isset( $_POST['auto_reviews_rating'] ) ) {
            $rating = intval( $_POST['auto_reviews_rating'] );
            $rating = max( 1, min( 5, $rating ) );
            update_post_meta( $post_id, '_auto_reviews_rating', $rating );
        }

        $author = isset( $_POST['auto_reviews_author'] ) ? sanitize_text_field( $_POST['auto_reviews_author'] ) : '';
        update_post_meta( $post_id, '_auto_reviews_author', $author );

        $author_url = isset( $_POST['auto_reviews_author_url'] ) ? esc_url_raw( $_POST['auto_reviews_author_url'] ) : '';
        update_post_meta( $post_id, '_auto_reviews_author_url', $author_url );

        if ( isset( $_POST['auto_reviews_source'] ) ) {
            update_post_meta( $post_id, '_auto_reviews_source', sanitize_text_field( $_POST['auto_reviews_source'] ) );
        }

        $queued = isset( $_POST['auto_reviews_queued'] ) ? '1' : '0';
        update_post_meta( $post_id, '_auto_reviews_queued', $queued );

        $post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( $_POST['post_status'] ) : '';
        if ( 'publish' === $post_status ) {
            update_post_meta( $post_id, '_auto_reviews_queued', '0' );
        }
    }

    /**
     * Handle post status transitions: remove from the queue when published.
     */
    public function on_post_status_change( $new_status, $old_status, $post ) {
        if ( self::CPT !== $post->post_type ) {
            return;
        }

        // If a review transitions to "publish", remove it from the queue and create a comment.
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            update_post_meta( $post->ID, '_auto_reviews_queued', '0' );
            $this->maybe_create_comment_from_review( $post->ID );
        }
    }

    protected function maybe_create_comment_from_review( $review_id ) {
        return $this->create_comment_from_review( $review_id );
    }

    protected function create_comment_from_review( $review_id ) {
        $existing_comment_id = get_post_meta( $review_id, '_auto_reviews_comment_id', true );
        if ( $existing_comment_id ) {
            return (int) $existing_comment_id;
        }

        $review = get_post( $review_id );
        if ( ! $review || self::CPT !== $review->post_type ) {
            return false;
        }

        $author     = get_post_meta( $review_id, '_auto_reviews_author', true );
        $author_url = get_post_meta( $review_id, '_auto_reviews_author_url', true );
        $rating     = (int) get_post_meta( $review_id, '_auto_reviews_rating', true );
        $rating     = max( 1, min( 5, $rating ) );

        $settings = $this->get_settings();
        $post_id  = (int) ( isset( $settings['comments_post_id'] ) ? $settings['comments_post_id'] : 0 );
        if ( $post_id <= 0 ) {
            $posts = get_posts(
                [
                    'post_type'      => [ 'post', 'page' ],
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ]
            );
            if ( empty( $posts ) ) {
                return false;
            }
            $post_id = $posts[0]->ID;
        }

        $target_post = get_post( $post_id );
        if ( ! $target_post || 'publish' !== $target_post->post_status ) {
            return false;
        }

        $author_email = '';
        if ( $author_url ) {
            $domain = parse_url( $author_url, PHP_URL_HOST );
            if ( $domain ) {
                $author_email = 'review-' . sanitize_key( $author ) . '@' . $domain;
            }
        }
        if ( ! $author_email ) {
            $author_email = 'review-' . sanitize_key( $author ) . '@example.com';
        }

        $comment_content = $review->post_content;

        $comment_data = [
            'comment_post_ID'      => $post_id,
            'comment_author'       => $author ? $author : __( 'Аноним', 'auto-reviews' ),
            'comment_author_email' => $author_email,
            'comment_author_url'   => $author_url ? $author_url : '',
            'comment_content'      => $comment_content,
            'comment_type'         => 'comment',
            'comment_approved'     => 1,
            'comment_meta'         => [
                'auto_review_id' => $review_id,
                'rating'         => $rating,
            ],
        ];

        $comment_id = wp_insert_comment( $comment_data );

        if ( $comment_id && ! is_wp_error( $comment_id ) ) {
            update_post_meta( $review_id, '_auto_reviews_comment_id', $comment_id );
            update_comment_meta( $comment_id, 'auto_review_id', $review_id );
            update_comment_meta( $comment_id, 'rating', $rating );

            return $comment_id;
        }

        return false;
    }

    /**
     * Hides the date for comments created from reviews.
     */
    public function hide_comment_date( $date, $format, $comment ) {
        if ( ! $comment || ! isset( $comment->comment_ID ) ) {
            return $date;
        }
        $review_id = get_comment_meta( $comment->comment_ID, 'auto_review_id', true );
        if ( $review_id ) {
            $settings = $this->get_settings();
            if ( empty( $settings['show_date_in_reviews'] ) ) {
                return '';
            }
        }
        return $date;
    }

    /**
     * Hides the time for comments created from reviews.
     */
    public function hide_comment_time( $time, $format, $comment ) {
        if ( ! $comment || ! isset( $comment->comment_ID ) ) {
            return $time;
        }
        $review_id = get_comment_meta( $comment->comment_ID, 'auto_review_id', true );
        if ( $review_id ) {
            $settings = $this->get_settings();
            if ( empty( $settings['show_date_in_reviews'] ) ) {
                return '';
            }
        }
        return $time;
    }

    /**
     * Hides the displayed date output for comments created from reviews.
     */
    public function hide_comment_date_output( $date, $format, $comment ) {
        if ( ! $comment || ! isset( $comment->comment_ID ) ) {
            return $date;
        }
        $review_id = get_comment_meta( $comment->comment_ID, 'auto_review_id', true );
        if ( $review_id ) {
            $settings = $this->get_settings();
            if ( empty( $settings['show_date_in_reviews'] ) ) {
                return '';
            }
        }
        return $date;
    }

    /**
     * Hides the displayed time output for comments created from reviews.
     */
    public function hide_comment_time_output( $time, $format, $comment ) {
        if ( ! $comment || ! isset( $comment->comment_ID ) ) {
            return $time;
        }
        $review_id = get_comment_meta( $comment->comment_ID, 'auto_review_id', true );
        if ( $review_id ) {
            $settings = $this->get_settings();
            if ( empty( $settings['show_date_in_reviews'] ) ) {
                return '';
            }
        }
        return $time;
    }

    /**
     * Adds admin CSS to hide the date for comments created from reviews.
     */
    public function add_admin_css() {
        $settings = $this->get_settings();
        if ( ! empty( $settings['show_date_in_reviews'] ) ) {
            return;
        }
        ?>
        <style>
            .comment-item[data-review-comment="true"] .comment-date,
            .comment-item[data-review-comment="true"] .comment-time,
            tr[data-review-comment="true"] .column-date,
            tr[data-review-comment="true"] .column-response {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Adds frontend CSS to hide the date for comments created from reviews.
     */
    public function add_frontend_css() {
        $settings = $this->get_settings();
        if ( ! empty( $settings['show_date_in_reviews'] ) ) {
            return;
        }
        ?>
        <style>
            .comment[data-review-comment="true"] .comment-metadata,
            .comment[data-review-comment="true"] .comment-date,
            .comment[data-review-comment="true"] .comment-time {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Adds rating stars to the review comment text.
     */
    public function add_rating_stars_to_comment( $comment_text, $comment ) {
        if ( ! $comment || ! isset( $comment->comment_ID ) ) {
            return $comment_text;
        }

        $review_id = get_comment_meta( $comment->comment_ID, 'auto_review_id', true );
        if ( ! $review_id ) {
            return $comment_text;
        }

        $rating = get_comment_meta( $comment->comment_ID, 'rating', true );
        if ( ! $rating ) {
            $rating = intval( get_post_meta( $review_id, '_auto_reviews_rating', true ) );
        }
        $rating = max( 1, min( 5, intval( $rating ) ) );

        $stars = str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );

        return '<div class="auto-review-rating" style="margin-bottom: 10px; font-size: 1.2em;">' . esc_html( $stars ) . '</div>' . $comment_text;
    }

    /**
     * Admin menu.
     */
    public function register_admin_menu() {
        $cap = 'manage_options';

        add_menu_page(
            __( 'Auto Reviews', 'auto-reviews' ),
            __( 'Auto Reviews', 'auto-reviews' ),
            $cap,
            'auto-reviews',
            [ $this, 'render_reviews_page' ],
            'dashicons-star-filled',
            26
        );

        add_submenu_page(
            'auto-reviews',
            __( 'Все отзывы', 'auto-reviews' ),
            __( 'Все отзывы', 'auto-reviews' ),
            $cap,
            'edit.php?post_type=' . self::CPT
        );

        add_submenu_page(
            'auto-reviews',
            __( 'Импорт из Google Таблиц', 'auto-reviews' ),
            __( 'Импорт', 'auto-reviews' ),
            $cap,
            'auto-reviews-import',
            [ $this, 'render_import_page' ]
        );

        add_submenu_page(
            'auto-reviews',
            __( 'Генерация через GPT', 'auto-reviews' ),
            __( 'GPT генерация', 'auto-reviews' ),
            $cap,
            'auto-reviews-gpt',
            [ $this, 'render_gpt_page' ]
        );

        add_submenu_page(
            'auto-reviews',
            __( 'Настройки', 'auto-reviews' ),
            __( 'Настройки', 'auto-reviews' ),
            $cap,
            'auto-reviews-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_reviews_page() {
        $count = wp_count_posts( self::CPT );
        $published = isset( $count->publish ) ? (int) $count->publish : 0;
        $draft = isset( $count->draft ) ? (int) $count->draft : 0;
        echo '<div class="wrap"><h1>' . esc_html__( 'Auto Reviews', 'auto-reviews' ) . '</h1>';
        echo '<p>' . sprintf( esc_html__( 'Опубликовано: %d. В черновиках/очереди: %d.', 'auto-reviews' ), $published, $draft ) . '</p>';
        echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ) . '" class="button button-primary">' . esc_html__( 'Перейти к списку отзывов', 'auto-reviews' ) . '</a></p></div>';
    }

    /**
     * Settings registration.
     */
    public function register_settings() {
        register_setting(
            'auto_reviews_settings_group',
            self::OPTION_SETTINGS,
            [
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
            ]
        );

        add_settings_section(
            'auto_reviews_main',
            __( 'Основные настройки Auto Reviews', 'auto-reviews' ),
            '__return_false',
            'auto-reviews-settings'
        );

        add_settings_field(
            'publish_min',
            __( 'Мин. отзывов за один запуск (в месяц)', 'auto-reviews' ),
            [ $this, 'field_number_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'publish_min',
                'option'    => 'publish_min',
                'min'       => 1,
                'max'       => 50,
                'default'   => 1,
            ]
        );

        add_settings_field(
            'publish_max',
            __( 'Макс. отзывов за один запуск', 'auto-reviews' ),
            [ $this, 'field_number_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'publish_max',
                'option'    => 'publish_max',
                'min'       => 1,
                'max'       => 50,
                'default'   => 4,
            ]
        );

        add_settings_field(
            'publish_frequency',
            __( 'Частота автоматической публикации', 'auto-reviews' ),
            [ $this, 'field_select_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'publish_frequency',
                'option'    => 'publish_frequency',
                'options'   => [
                    'daily'      => __( 'Раз в день', 'auto-reviews' ),
                    'twicedaily' => __( 'Дважды в день', 'auto-reviews' ),
                    '3days'      => __( 'Раз в 3 дня', 'auto-reviews' ),
                    'weekly'     => __( 'Раз в неделю', 'auto-reviews' ),
                    '2weeks'     => __( 'Раз в 2 недели', 'auto-reviews' ),
                    'monthly'    => __( 'Раз в месяц', 'auto-reviews' ),
                ],
                'default'   => 'monthly',
            ]
        );

        add_settings_field(
            'gpt_api_key',
            __( 'GPT API ключ (fallback, если нет ENV)', 'auto-reviews' ),
            [ $this, 'field_text_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'gpt_api_key',
                'option'    => 'gpt_api_key',
                'default'   => '',
            ]
        );

        add_settings_field(
            'gpt_prompt_template',
            __( 'GPT prompt template', 'auto-reviews' ),
            [ $this, 'field_textarea_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'gpt_prompt_template',
                'option'    => 'gpt_prompt_template',
                'default'   => $this->get_default_gpt_prompt_template(),
                'rows'      => 6,
            ]
        );

        add_settings_field(
            'comments_post_id',
            __( 'ID поста/страницы для комментариев', 'auto-reviews' ),
            [ $this, 'field_number_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'comments_post_id',
                'option'    => 'comments_post_id',
                'min'       => 0,
                'max'       => 999999,
                'default'   => 0,
            ]
        );

        add_settings_field(
            'show_date_in_reviews',
            __( 'Показывать дату в отзывах', 'auto-reviews' ),
            [ $this, 'field_checkbox_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'show_date_in_reviews',
                'option'    => 'show_date_in_reviews',
                'default'   => 0,
            ]
        );

        add_settings_field(
            'external_reviews_offset',
            __( 'Дополнительные отзывы (вне плагина)', 'auto-reviews' ),
            [ $this, 'field_number_callback' ],
            'auto-reviews-settings',
            'auto_reviews_main',
            [
                'label_for' => 'external_reviews_offset',
                'option'    => 'external_reviews_offset',
                'min'       => 0,
                'max'       => 999999,
                'default'   => 0,
            ]
        );
    }

    public function get_settings() {
        $defaults = [
            'publish_min'         => 1,
            'publish_max'         => 4,
            'publish_frequency'   => 'monthly',
            'gpt_api_key'         => '',
            'gpt_prompt_template' => $this->get_default_gpt_prompt_template(),
            'comments_post_id'    => 0,
            'show_date_in_reviews' => 0,
            'external_reviews_offset' => 0,
        ];
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( $options, $defaults );
    }

    /**
     * Default GPT prompt template.
     *
     * Available placeholders:
     * - %LANG%     Language instruction string
     * - %COUNT%    Number of reviews
     * - %BUSINESS% Business/site name
     */
    protected function get_default_gpt_prompt_template() {
        return '%LANG%Сгенерируй %COUNT% реалистичных, развернутых, положительных отзывов о компании/сайте "%BUSINESS%". Ответ верни строго в формате JSON-массива объектов со следующими полями: "author" (имя выдуманного автора), "rating" (целое число от 4 до 5), "content" (текст отзыва, 2-4 предложения). Без пояснений, только JSON.';
    }

    /**
     * Sanitize settings array.
     *
     * @param array $input Raw settings.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : [];
        $out   = [];

        $out['publish_min'] = isset( $input['publish_min'] ) ? max( 1, min( 50, intval( $input['publish_min'] ) ) ) : 1;
        $out['publish_max'] = isset( $input['publish_max'] ) ? max( 1, min( 50, intval( $input['publish_max'] ) ) ) : 4;

        $allowed_freq = [ 'daily', 'twicedaily', '3days', 'weekly', '2weeks', 'monthly' ];
        $freq         = isset( $input['publish_frequency'] ) ? sanitize_text_field( $input['publish_frequency'] ) : 'monthly';
        $out['publish_frequency'] = in_array( $freq, $allowed_freq, true ) ? $freq : 'monthly';

        $out['gpt_api_key'] = isset( $input['gpt_api_key'] ) ? sanitize_text_field( $input['gpt_api_key'] ) : '';

        $template = isset( $input['gpt_prompt_template'] ) ? sanitize_textarea_field( $input['gpt_prompt_template'] ) : '';
        $out['gpt_prompt_template'] = ( '' !== trim( $template ) ) ? $template : $this->get_default_gpt_prompt_template();

        $out['comments_post_id'] = isset( $input['comments_post_id'] ) ? max( 0, intval( $input['comments_post_id'] ) ) : 0;
        $out['show_date_in_reviews'] = ! empty( $input['show_date_in_reviews'] ) ? 1 : 0;
        $out['external_reviews_offset'] = isset( $input['external_reviews_offset'] ) ? max( 0, intval( $input['external_reviews_offset'] ) ) : 0;

        return $out;
    }

    public function field_number_callback( $args ) {
        $settings = $this->get_settings();
        $option   = $args['option'];
        $value    = isset( $settings[ $option ] ) ? intval( $settings[ $option ] ) : intval( $args['default'] );
        $min      = isset( $args['min'] ) ? intval( $args['min'] ) : 0;
        $max      = isset( $args['max'] ) ? intval( $args['max'] ) : 9999;
        ?>
        <input type="number"
               id="<?php echo esc_attr( $option ); ?>"
               name="<?php echo esc_attr( self::OPTION_SETTINGS . '[' . $option . ']' ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               min="<?php echo esc_attr( $min ); ?>"
               max="<?php echo esc_attr( $max ); ?>">
        <?php
    }

    public function field_checkbox_callback( $args ) {
        $settings = $this->get_settings();
        $option   = $args['option'];
        $value    = isset( $settings[ $option ] ) ? intval( $settings[ $option ] ) : intval( $args['default'] );
        ?>
        <label>
            <input type="checkbox"
                   id="<?php echo esc_attr( $option ); ?>"
                   name="<?php echo esc_attr( self::OPTION_SETTINGS . '[' . $option . ']' ); ?>"
                   value="1" <?php checked( $value, 1 ); ?>>
        </label>
        <?php
    }

    public function field_text_callback( $args ) {
        $settings = $this->get_settings();
        $option   = $args['option'];
        $value    = isset( $settings[ $option ] ) ? $settings[ $option ] : $args['default'];
        ?>
        <input type="password"
               id="<?php echo esc_attr( $option ); ?>"
               name="<?php echo esc_attr( self::OPTION_SETTINGS . '[' . $option . ']' ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
               autocomplete="off">
        <p class="description">
            <?php esc_html_e( 'Ключ в настройках робит, но есть енв файл и если там есть ключ он будет в приоритете.', 'auto-reviews' ); ?>
        </p>
        <?php
    }

    public function field_textarea_callback( $args ) {
        $settings = $this->get_settings();
        $option   = $args['option'];
        $value    = isset( $settings[ $option ] ) ? $settings[ $option ] : $args['default'];
        $rows     = isset( $args['rows'] ) ? max( 3, intval( $args['rows'] ) ) : 6;
        ?>
        <textarea
            id="<?php echo esc_attr( $option ); ?>"
            name="<?php echo esc_attr( self::OPTION_SETTINGS . '[' . $option . ']' ); ?>"
            rows="<?php echo esc_attr( $rows ); ?>"
            class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'Доступные переменные: %LANG% (инструкция языка), %COUNT% (кол-во отзывов), %BUSINESS% (название).', 'auto-reviews' ); ?>
        </p>
        <?php
    }

    public function field_select_callback( $args ) {
        $settings = $this->get_settings();
        $option   = $args['option'];
        $value    = isset( $settings[ $option ] ) ? $settings[ $option ] : $args['default'];
        $options  = isset( $args['options'] ) ? $args['options'] : [];
        ?>
        <select id="<?php echo esc_attr( $option ); ?>"
                name="<?php echo esc_attr( self::OPTION_SETTINGS . '[' . $option . ']' ); ?>">
            <?php foreach ( $options as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Auto Reviews — настройки', 'auto-reviews' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'auto_reviews_settings_group' );
                do_settings_sections( 'auto-reviews-settings' );
                submit_button();
                ?>
            </form>

            <h2><?php esc_html_e( 'Ручная публикация', 'auto-reviews' ); ?></h2>
            <p>
                <button type="button" id="auto-reviews-publish-now" class="button button-primary">
                    <?php esc_html_e( 'Опубликовать отзывы из очереди сейчас', 'auto-reviews' ); ?>
                </button>
                <span id="auto-reviews-publish-result" style="margin-left: 10px;"></span>
            </p>
            <p class="description">
                <?php esc_html_e( 'Опубликует случайное количество отзывов из очереди (согласно настройкам мин/макс).', 'auto-reviews' ); ?>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#auto-reviews-publish-now').on('click', function() {
                var $btn = $(this);
                var $result = $('#auto-reviews-publish-result');
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Публикация...', 'auto-reviews' ) ); ?>');
                $result.html('');
                $.post(ajaxurl, {
                    action: 'auto_reviews_publish_now',
                    nonce: '<?php echo wp_create_nonce( 'auto_reviews_publish_now' ); ?>'
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Опубликовать отзывы из очереди сейчас', 'auto-reviews' ) ); ?>');
                    if (response.success) {
                        $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: red;">' + (response.data.message || '<?php echo esc_js( __( 'Ошибка', 'auto-reviews' ) ); ?>') + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Cron schedule registration.
     */
    public function register_monthly_schedule( $schedules ) {
        // Add custom intervals.
        if ( ! isset( $schedules['auto_reviews_3days'] ) ) {
            $schedules['auto_reviews_3days'] = [
                'interval' => 3 * DAY_IN_SECONDS,
                'display'  => __( 'Раз в 3 дня (Auto Reviews)', 'auto-reviews' ),
            ];
        }
        if ( ! isset( $schedules['auto_reviews_2weeks'] ) ) {
            $schedules['auto_reviews_2weeks'] = [
                'interval' => 14 * DAY_IN_SECONDS,
                'display'  => __( 'Раз в 2 недели (Auto Reviews)', 'auto-reviews' ),
            ];
        }
        if ( ! isset( $schedules['auto_reviews_monthly'] ) ) {
            $schedules['auto_reviews_monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Раз в месяц (Auto Reviews)', 'auto-reviews' ),
            ];
        }
        return $schedules;
    }

    public static function activate() {
        // Register CPT before flush.
        $plugin = new self();
        $plugin->register_cpt();
        flush_rewrite_rules();

        // Schedule cron based on the current settings.
        $plugin->schedule_cron();
    }

    /**
     * Schedules the cron task according to the configured frequency.
     */
    public function schedule_cron() {
        // Clear old schedules.
        wp_clear_scheduled_hook( self::CRON_HOOK );

        $settings = $this->get_settings();
        $frequency = isset( $settings['publish_frequency'] ) ? $settings['publish_frequency'] : 'monthly';

        // Map frequency to a WordPress cron interval.
        $schedule_map = [
            'daily'      => 'daily',
            'twicedaily' => 'twicedaily',
            '3days'      => 'auto_reviews_3days',
            'weekly'     => 'weekly',
            '2weeks'     => 'auto_reviews_2weeks',
            'monthly'    => 'auto_reviews_monthly',
        ];

        $schedule = isset( $schedule_map[ $frequency ] ) ? $schedule_map[ $frequency ] : 'auto_reviews_monthly';

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
        }
    }

    /**
     * Ensures the cron is scheduled (useful if wp-cron events were lost).
     */
    public function maybe_reschedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $this->schedule_cron();
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        flush_rewrite_rules();
    }

    /**
     * Publish reviews from queue (1-4 or per settings).
     */
    public function cron_publish_from_queue() {
        $result = $this->publish_reviews_from_queue();
        return $result;
    }

    /**
     * Publishes reviews from the queue (used by cron and AJAX).
     *
     * @return array|WP_Error
     */
    protected function publish_reviews_from_queue() {
        $settings = $this->get_settings();
        $min      = max( 1, intval( $settings['publish_min'] ) );
        $max      = max( $min, intval( $settings['publish_max'] ) );
        $count    = rand( $min, $max );

        $args = [
            'post_type'      => self::CPT,
            'posts_per_page' => $count,
            'post_status'    => [ 'draft', 'pending' ],
            'meta_query'     => [
                [
                    'key'   => '_auto_reviews_queued',
                    'value' => '1',
                ],
            ],
            'orderby'        => 'rand',
        ];

        $queued = get_posts( $args );

        if ( empty( $queued ) ) {
            return [
                'published' => 0,
                'message'   => __( 'Нет отзывов в очереди для публикации.', 'auto-reviews' ),
            ];
        }

        $published = 0;

        foreach ( $queued as $post ) {
            wp_update_post(
                [
                    'ID'          => $post->ID,
                    'post_status' => 'publish',
                ]
            );
            update_post_meta( $post->ID, '_auto_reviews_queued', '0' );
            $this->maybe_create_comment_from_review( $post->ID );
            $published++;
        }

        return [
            'published' => $published,
            'message'   => sprintf( __( 'Опубликовано отзывов: %d', 'auto-reviews' ), $published ),
        ];
    }

    /**
     * AJAX обработчик для ручной публикации.
     */
    public function ajax_publish_now() {
        check_ajax_referer( 'auto_reviews_publish_now', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Недостаточно прав.', 'auto-reviews' ) ] );
        }

        $result = $this->publish_reviews_from_queue();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public function render_import_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $message = '';

        if ( isset( $_POST['auto_reviews_import_nonce'] ) && wp_verify_nonce( $_POST['auto_reviews_import_nonce'], 'auto_reviews_import' ) ) {
            $csv_url = isset( $_POST['auto_reviews_csv_url'] ) ? esc_url_raw( $_POST['auto_reviews_csv_url'] ) : '';

            if ( $csv_url ) {
                $result = $this->import_from_csv_url( $csv_url );
                if ( is_wp_error( $result ) ) {
                    $message = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                } else {
                    $message = '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Импортировано отзывов: %d', 'auto-reviews' ), intval( $result ) ) . '</p></div>';
                }
            } else {
                $message = '<div class="notice notice-error"><p>' . esc_html__( 'Укажите URL CSV-файла.', 'auto-reviews' ) . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Импорт отзывов из Google Таблиц', 'auto-reviews' ); ?></h1>
            <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <form method="post">
                <?php wp_nonce_field( 'auto_reviews_import', 'auto_reviews_import_nonce' ); ?>
                <p>
                    <label for="auto_reviews_csv_url"><?php esc_html_e( 'URL Google Таблицы или CSV', 'auto-reviews' ); ?></label><br>
                    <input type="url" class="large-text" name="auto_reviews_csv_url" id="auto_reviews_csv_url" placeholder="https://docs.google.com/spreadsheets/d/..." required style="width: 100%; max-width: 800px;">
                </p>
                <p>
                    <?php submit_button( __( 'Импортировать', 'auto-reviews' ), 'primary', 'submit', false ); ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * CSV import helper.
     * Expected columns: author, rating, content, source, queued (0/1), author_url.
     */
    protected function import_from_csv_url( $url ) {
        $url = $this->convert_google_sheets_url_to_csv( $url );

        $response = wp_remote_get( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $error_message = sprintf( __( 'Ошибка при загрузке CSV (код %d)', 'auto-reviews' ), intval( $code ) );
            
            if ( 401 === $code || 403 === $code ) {
                $error_message .= '. ' . __( 'Таблица должна быть публичной (доступна всем по ссылке). В Google Sheets: Файл → Поделиться → Доступ по ссылке → Все, у кого есть ссылка.', 'auto-reviews' );
            } elseif ( 404 === $code ) {
                $error_message .= '. ' . __( 'Таблица не найдена. Проверьте правильность ссылки.', 'auto-reviews' );
            }
            
            return new \WP_Error( 'auto_reviews_csv_http', $error_message );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! $body ) {
            return new \WP_Error( 'auto_reviews_csv_empty', __( 'Пустой ответ от CSV.', 'auto-reviews' ) );
        }

        $lines = preg_split( '/\r\n|\r|\n/', $body );
        if ( ! $lines ) {
            return new \WP_Error( 'auto_reviews_csv_parse', __( 'Не удалось разобрать CSV.', 'auto-reviews' ) );
        }

        $header = str_getcsv( array_shift( $lines ) );
        $header = array_map( 'trim', $header );

        $count = 0;

        foreach ( $lines as $line ) {
            if ( '' === trim( $line ) ) {
                continue;
            }

            $row = str_getcsv( $line );
            if ( ! $row || count( $row ) === 0 ) {
                continue;
            }

            $data = array_combine( $header, $row );
            if ( ! $data ) {
                continue;
            }

            $author     = isset( $data['author'] ) ? sanitize_text_field( $data['author'] ) : '';
            $rating     = isset( $data['rating'] ) ? intval( $data['rating'] ) : 5;
            $content    = isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '';
            $source     = isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : 'sheet';
            $queued     = isset( $data['queued'] ) ? ( $data['queued'] ? '1' : '0' ) : '1';
            $author_url = isset( $data['author_url'] ) ? esc_url_raw( $data['author_url'] ) : '';

            $post_id = wp_insert_post(
                [
                    'post_type'    => self::CPT,
                    'post_status'  => 'publish' === $queued ? 'publish' : 'draft',
                    'post_title'   => wp_trim_words( wp_strip_all_tags( $content ), 6, '...' ),
                    'post_content' => $content,
                ]
            );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, '_auto_reviews_author', $author );
                update_post_meta( $post_id, '_auto_reviews_rating', max( 1, min( 5, $rating ) ) );
                update_post_meta( $post_id, '_auto_reviews_source', $source );
                if ( $author_url ) {
                    update_post_meta( $post_id, '_auto_reviews_author_url', $author_url );
                }
                $queued_value = $queued === '1' ? '1' : '0';
                update_post_meta( $post_id, '_auto_reviews_queued', $queued_value );
                if ( 'publish' === $queued_value || 'publish' === $queued ) {
                    $this->maybe_create_comment_from_review( $post_id );
                }
                
                $count++;
            }
        }

        return $count;
    }

    /**
     * Converts a Google Sheets URL into a CSV export URL.
     *
     * @param string $url Source URL.
     * @return string CSV export URL.
     */
    protected function convert_google_sheets_url_to_csv( $url ) {
        if ( strpos( $url, '/export?format=csv' ) !== false || strpos( $url, '.csv' ) !== false ) {
            return $url;
        }

        if ( strpos( $url, 'docs.google.com/spreadsheets' ) === false ) {
            return $url;
        }

        if ( preg_match( '/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches ) ) {
            $spreadsheet_id = $matches[1];
            $gid = '0';
            if ( preg_match( '/[#&]gid=(\d+)/', $url, $gid_matches ) ) {
                $gid = $gid_matches[1];
            }

            return sprintf(
                'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
                $spreadsheet_id,
                $gid
            );
        }

        return $url;
    }

    /**
     * Language mapping for selecting review language during GPT generation.
     * Key is the API value; value is the label shown in the dropdown.
     *
     * @return array<string, string>
     */
    public function get_gpt_languages() {
        return [
            ''                    => __( 'English (по умолчанию)', 'auto-reviews' ),
            'English'             => 'English',
            'Español'             => 'Español',
            'Deutsch'             => 'Deutsch',
            'Français'            => 'Français',
            'Italiano'            => 'Italiano',
            'Português'           => 'Português',
            'Português brasileiro'=> 'Português (Brasil)',
            'Polski'              => 'Polski',
            'Nederlands'          => 'Nederlands',
            'Magyar'              => 'Magyar',
            'Čeština'             => 'Čeština',
            'Română'              => 'Română',
            'Български'           => 'Български',
            'Ελληνικά'            => 'Ελληνικά',
            'Svenska'              => 'Svenska',
            'Norsk'               => 'Norsk',
            'Dansk'               => 'Dansk',
            'Suomi'               => 'Suomi',
            'Català'              => 'Català',
            'Hrvatski'            => 'Hrvatski',
            'Slovenčina'           => 'Slovenčina',
            'Slovenščina'         => 'Slovenščina',
            'Srpski'              => 'Srpski',
            'Lietuvių'            => 'Lietuvių',
            'Latviešu'            => 'Latviešu',
            'Eesti'               => 'Eesti',
            'Íslenska'            => 'Íslenska',
            'Gaeilge'             => 'Gaeilge',
            'Cymraeg'             => 'Cymraeg',
            'Euskara'             => 'Euskara',
            'Galego'              => 'Galego',
            'Malti'               => 'Malti',
            'Shqip'               => 'Shqip',
            'Македонски'          => 'Македонски',
            'Беларуская'          => 'Беларуская',
            'Español (Latinoamérica)' => 'Español (Latinoamérica)',
            '日本語'              => '日本語',
            '中文'                => '中文',
            '한국어'              => '한국어',
            'Türkçe'              => 'Türkçe',
            'العربية'            => 'العربية',
            'हिन्दी'              => 'हिन्दी',
            'ไทย'                => 'ไทย',
            'Tiếng Việt'          => 'Tiếng Việt',
            'Bahasa Indonesia'   => 'Bahasa Indonesia',
            'Bahasa Melayu'       => 'Bahasa Melayu',
            'Filipino'            => 'Filipino',
            'Українська'          => 'Українська',
            'Русский'             => 'Русский',
        ];
    }

    /**
     * GPT generation page.
     */
    public function render_gpt_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings      = $this->get_settings();
        $option_apiKey = isset( $settings['gpt_api_key'] ) ? $settings['gpt_api_key'] : '';
        $env_apiKey    = Env::get( 'AUTO_REVIEWS_GPT_API_KEY' );
        $api_key       = $env_apiKey ? $env_apiKey : $option_apiKey;
        $message = '';

            if ( isset( $_POST['auto_reviews_gpt_nonce'] ) && wp_verify_nonce( $_POST['auto_reviews_gpt_nonce'], 'auto_reviews_gpt' ) ) {
            if ( ! $api_key ) {
                $message = '<div class="notice notice-error"><p>' . esc_html__( 'Укажите GPT API ключ в настройках плагина или через переменную окружения AUTO_REVIEWS_GPT_API_KEY (например в .env в корне плагина).', 'auto-reviews' ) . '</p></div>';
            } else {
                $business_name = isset( $_POST['business_name'] ) ? sanitize_text_field( $_POST['business_name'] ) : '';
                $count         = isset( $_POST['reviews_count'] ) ? max( 1, min( 50, intval( $_POST['reviews_count'] ) ) ) : 5;
                $language      = isset( $_POST['reviews_language'] ) ? sanitize_text_field( $_POST['reviews_language'] ) : '';

                $result = $this->generate_reviews_via_gpt( $api_key, $business_name, $count, $language );
                if ( is_wp_error( $result ) ) {
                    $message = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                } else {
                    $message = '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Сгенерировано и добавлено отзывов: %d', 'auto-reviews' ), intval( $result ) ) . '</p></div>';
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Генерация отзывов через GPT', 'auto-reviews' ); ?></h1>
            <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <p><?php esc_html_e( 'Отзывы сразу падают в отложку, максимум по полтосу генерить можно.', 'auto-reviews' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'auto_reviews_gpt', 'auto_reviews_gpt_nonce' ); ?>
                <p>
                    <label for="business_name"><?php esc_html_e( 'Название компании / сайта', 'auto-reviews' ); ?></label><br>
                    <input type="text" class="regular-text" name="business_name" id="business_name" required>
                </p>
                <p>
                    <label for="reviews_count"><?php esc_html_e( 'Количество отзывов для генерации', 'auto-reviews' ); ?></label><br>
                    <input type="number" name="reviews_count" id="reviews_count" min="1" max="50" value="5">
                </p>
                <p>
                    <label for="reviews_language"><?php esc_html_e( 'Язык отзывов', 'auto-reviews' ); ?></label><br>
                    <input type="text" class="regular-text" id="reviews_language_search" placeholder="<?php esc_attr_e( 'Поиск языка...', 'auto-reviews' ); ?>" autocomplete="off" style="max-width: 300px; margin-bottom: 6px;">
                    <br>
                    <select name="reviews_language" id="reviews_language" class="regular-text" style="max-width: 300px;">
                        <?php foreach ( $this->get_gpt_languages() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <?php submit_button( __( 'Сгенерировать', 'auto-reviews' ), 'primary', 'submit', false ); ?>
                </p>
            </form>
            <script>
            (function(){
                var search = document.getElementById('reviews_language_search');
                var select = document.getElementById('reviews_language');
                if (!search || !select) return;
                var options = [].slice.call(select.options);
                search.addEventListener('input', function(){
                    var q = (this.value || '').toLowerCase().trim();
                    options.forEach(function(opt){
                        var text = (opt.textContent || '').toLowerCase();
                        opt.disabled = q && text.indexOf(q) === -1;
                    });
                });
                search.addEventListener('focus', function(){ select.size = Math.min(12, options.length); });
                search.addEventListener('blur', function(){ setTimeout(function(){ select.size = 1; }, 200); });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Call GPT API and create queued reviews.
     *
     * @param string $api_key      API key.
     * @param string $business_name Business/site name.
     * @param int    $count        Number of reviews.
     * @param string $language     Language for reviews (e.g. "русский", "English"). Empty = Russian.
     */
    protected function generate_reviews_via_gpt( $api_key, $business_name, $count, $language = '' ) {
        $settings = $this->get_settings();
        if ( '' === trim( $language ) ) {
            $lang_instruction = 'All reviews must be written in English. ';
        } else {
            $lang_instruction = sprintf(
                'Все отзывы должны быть написаны на языке: %s. ',
                trim( $language )
            );
        }
        $template = isset( $settings['gpt_prompt_template'] ) ? $settings['gpt_prompt_template'] : $this->get_default_gpt_prompt_template();
        if ( '' === trim( (string) $template ) ) {
            $template = $this->get_default_gpt_prompt_template();
        }

        $prompt = strtr(
            (string) $template,
            [
                '%LANG%'     => (string) $lang_instruction,
                '%COUNT%'    => (string) intval( $count ),
                '%BUSINESS%' => (string) $business_name,
            ]
        );

        $body = [
            'model'    => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 60,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new \WP_Error( 'auto_reviews_gpt_http', sprintf( __( 'Ошибка GPT API (код %d)', 'auto-reviews' ), intval( $code ) ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $data || empty( $data['choices'][0]['message']['content'] ) ) {
            return new \WP_Error( 'auto_reviews_gpt_parse', __( 'Не удалось разобрать ответ GPT.', 'auto-reviews' ) );
        }

        $json = trim( $data['choices'][0]['message']['content'] );

        // Try to extract JSON even if GPT included extra text.
        if ( preg_match( '/(\[.*\])\s*$/s', $json, $m ) ) {
            $json = $m[1];
        }

        $reviews = json_decode( $json, true );
        if ( ! is_array( $reviews ) ) {
            return new \WP_Error( 'auto_reviews_gpt_json', __( 'Ответ GPT не является корректным JSON.', 'auto-reviews' ) );
        }

        $created = 0;

        foreach ( $reviews as $r ) {
            if ( empty( $r['content'] ) ) {
                continue;
            }

            $author  = isset( $r['author'] ) ? sanitize_text_field( $r['author'] ) : '';
            $rating  = isset( $r['rating'] ) ? intval( $r['rating'] ) : 5;
            $content = wp_kses_post( $r['content'] );

            $post_id = wp_insert_post(
                [
                    'post_type'    => self::CPT,
                    'post_status'  => 'draft',
                    'post_title'   => wp_trim_words( wp_strip_all_tags( $content ), 6, '...' ),
                    'post_content' => $content,
                ]
            );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, '_auto_reviews_author', $author );
                update_post_meta( $post_id, '_auto_reviews_rating', max( 1, min( 5, $rating ) ) );
                update_post_meta( $post_id, '_auto_reviews_source', 'gpt' );
                update_post_meta( $post_id, '_auto_reviews_queued', '1' );
                $created++;
            }
        }

        return $created;
    }

    /**
     * Shortcode: количество опубликованных отзывов.
     */
    public function shortcode_reviews_count() {
        $count     = wp_count_posts( self::CPT );
        $published = isset( $count->publish ) ? intval( $count->publish ) : 0;
        return (string) $published;
    }

    /**
     * Shortcode: JSON-LD схема с актуальными отзывами.
     */
    public function shortcode_reviews_schema() {
        $schema = $this->build_schema();
        if ( ! $schema ) {
            return '';
        }

        return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
    }

    /**
     * Shortcode: список опубликованных отзывов (HTML).
     *
     * @param array $atts Атрибуты шорткода: limit, orderby, order.
     * @return string
     */
    public function shortcode_reviews_list( $atts ) {
        $atts = shortcode_atts(
            [
                'limit'   => 10,
                'orderby' => 'date',
                'order'   => 'DESC',
            ],
            $atts,
            'auto_reviews_list'
        );

        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => intval( $atts['limit'] ),
            'orderby'        => sanitize_text_field( $atts['orderby'] ),
            'order'          => sanitize_text_field( $atts['order'] ),
        ];

        $posts = get_posts( $args );

        if ( empty( $posts ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="auto-reviews-list">
            <?php foreach ( $posts as $post ) : ?>
                <?php
                $rating     = intval( get_post_meta( $post->ID, '_auto_reviews_rating', true ) );
                $author     = get_post_meta( $post->ID, '_auto_reviews_author', true );
                $author_url = get_post_meta( $post->ID, '_auto_reviews_author_url', true );
                $rating     = max( 1, min( 5, $rating ) );
                ?>
                <div class="auto-review-item" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                    <div class="auto-review-header" style="margin-bottom: 10px;">
                        <strong class="auto-review-author">
                            <?php if ( $author_url ) : ?>
                                <a href="<?php echo esc_url( $author_url ); ?>" target="_blank" rel="nofollow">
                                    <?php echo esc_html( $author ? $author : __( 'Аноним', 'auto-reviews' ) ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $author ? $author : __( 'Аноним', 'auto-reviews' ) ); ?>
                            <?php endif; ?>
                        </strong>
                        <span class="auto-review-rating" style="margin-left: 10px;">
                            <?php
                            for ( $i = 1; $i <= 5; $i++ ) {
                                echo $i <= $rating ? '★' : '☆';
                            }
                            ?>
                            (<?php echo esc_html( $rating ); ?>/5)
                        </span>
                        <?php
                        $settings = $this->get_settings();
                        if ( ! empty( $settings['show_date_in_reviews'] ) ) :
                            ?>
                        <span class="auto-review-date" style="margin-left: 10px; color: #666; font-size: 0.9em;">
                            <?php echo esc_html( get_the_date( '', $post ) ); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="auto-review-content">
                        <?php echo wp_kses_post( wpautop( $post->post_content ) ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * We don't output a separate Organization block in <head> — aggregateRating
     * is injected into the theme's static markup via auto_reviews_aggregate_rating_schema.
     * The [auto_reviews_schema] shortcode can still be used manually.
     */
    public function output_schema_in_head() {
        // Previously output the full Organization schema — now we only inject aggregateRating into the theme markup.
        return;
    }

    /**
     * Returns only the aggregateRating block for substitution into the theme's static markup.
     * Used by the theme/SchemaMarkup to replace all aggregateRating entries in its markup.
     *
     * @return array|null Array shaped like ['@type'=>'AggregateRating','ratingValue'=>...,'reviewCount'=>...,'bestRating'=>5,'worstRating'=>1] or null.
     */
    public function get_aggregate_rating_for_schema() {
        $schema = $this->build_schema();
        if ( ! $schema || empty( $schema['aggregateRating'] ) ) {
            return null;
        }
        return $schema['aggregateRating'];
    }

    /**
     * Build JSON-LD schema object using published reviews.
     */
    protected function build_schema() {
        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
        ];

        $posts = get_posts( $args );

        if ( ! $posts ) {
            return null;
        }

        $ratings = [];

        foreach ( $posts as $post ) {
            $rating = intval( get_post_meta( $post->ID, '_auto_reviews_rating', true ) );

            if ( $rating <= 0 ) {
                $rating = 5;
            }

            $ratings[] = $rating;
        }

        $avg_rating = array_sum( $ratings ) / max( 1, count( $ratings ) );

        // Base review count = number of published plugin reviews.
        $review_count = count( $ratings );

        // Add manual offset for external reviews (Google, Yandex, etc.).
        $settings = $this->get_settings();
        if ( ! empty( $settings['external_reviews_offset'] ) ) {
            $review_count += max( 0, (int) $settings['external_reviews_offset'] );
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Organization',
            'name'            => get_bloginfo( 'name' ),
            'url'             => home_url( '/' ),
            'aggregateRating' => [
                '@type'       => 'AggregateRating',
                'ratingValue' => round( $avg_rating, 2 ),
                'reviewCount' => $review_count,
                'bestRating'  => 5,
                'worstRating' => 1,
            ],
        ];

        return $schema;
    }
}

