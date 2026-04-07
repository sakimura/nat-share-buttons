<?php
/**
 * Plugin Name: NAT Share Buttons
 * Plugin URI:  https://github.com/nat-consulting/nat-share-buttons
 * Description: Lightweight share buttons with page view counter and social share links.
 * Version:     1.0.3
 * Author:      Nat Sakimura / NAT Consulting LLC
 * License:     MIT
 * Text Domain: nat-share-buttons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NSB_VERSION',    '1.0.3' );
define( 'NSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'init', function() {
    load_plugin_textdomain( 'nat-share-buttons', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// -----------------------------------------------------------------------
// Activation / Deactivation
// -----------------------------------------------------------------------

register_activation_hook( __FILE__, 'nsb_activate' );
function nsb_activate() {
    nsb_create_tables();
    update_option( 'nsb_db_version', '2' );
}

// Auto-upgrade DB for existing installs.
add_action( 'plugins_loaded', function() {
    if ( get_option( 'nsb_db_version' ) !== '2' ) {
        nsb_create_tables();
        update_option( 'nsb_db_version', '2' );
    }
} );

function nsb_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}nsb_clicks (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id    BIGINT UNSIGNED NOT NULL,
        network    VARCHAR(32)     NOT NULL,
        clicked_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_network (post_id, network)
    ) {$charset};" );
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}nsb_pageviews (
        post_id BIGINT UNSIGNED NOT NULL,
        count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (post_id)
    ) {$charset};" );
}

// -----------------------------------------------------------------------
// Enqueue assets
// -----------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', 'nsb_enqueue' );
function nsb_enqueue() {
    if ( ! is_singular() ) return;
    wp_enqueue_style(
        'nat-share-buttons',
        NSB_PLUGIN_URL . 'assets/nsb.css',
        [],
        NSB_VERSION
    );
    wp_enqueue_script(
        'nat-share-buttons',
        NSB_PLUGIN_URL . 'assets/nsb.js',
        [],
        NSB_VERSION,
        true
    );
    wp_localize_script( 'nat-share-buttons', 'NSB', [
        'ajaxurl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'nsb_click' ),
        'pv_nonce' => wp_create_nonce( 'nsb_pageview' ),
        'post_id'  => get_the_ID(),
    ] );
}

// -----------------------------------------------------------------------
// Page view helpers
// -----------------------------------------------------------------------

/**
 * Get page view count from wp_nsb_pageviews.
 */
function nsb_get_pageview_count( $post_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT count FROM {$wpdb->prefix}nsb_pageviews WHERE post_id = %d",
        $post_id
    ) );
}

/**
 * Total views = seed count + page views.
 */
function nsb_get_view_count( $post_id ) {
    $total  = (int) get_post_meta( $post_id, '_nsb_seed_count', true );
    $total += nsb_get_pageview_count( $post_id );
    return $total;
}

// -----------------------------------------------------------------------
// AJAX: record a click
// -----------------------------------------------------------------------

add_action( 'wp_ajax_nsb_click',        'nsb_ajax_click' );
add_action( 'wp_ajax_nopriv_nsb_click', 'nsb_ajax_click' );
function nsb_ajax_click() {
    check_ajax_referer( 'nsb_click', 'nonce' );
    $post_id = absint( $_POST['post_id'] ?? 0 );
    $network = sanitize_key( $_POST['network'] ?? '' );
    $allowed = [ 'x', 'linkedin', 'line' ];
    if ( ! $post_id || ! in_array( $network, $allowed, true ) ) {
        wp_send_json_error(); return;
    }

    // Rate limiting: max 1 click per IP per post per network per hour
    $ip          = preg_replace( '/[^0-9a-fA-F.:,]/', '', $_SERVER['REMOTE_ADDR'] ?? '' );
    $rate_key    = 'nsb_rl_' . md5( $ip . '_' . $post_id . '_' . $network );
    if ( get_transient( $rate_key ) ) {
        // Already counted recently - open the share URL but don't record again
        wp_send_json_success(); return;
    }
    set_transient( $rate_key, 1, HOUR_IN_SECONDS );

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'nsb_clicks',
        [ 'post_id' => $post_id, 'network' => $network ],
        [ '%d', '%s' ]
    );
    wp_send_json_success();
}

// -----------------------------------------------------------------------
// AJAX: record a page view
// -----------------------------------------------------------------------

add_action( 'wp_ajax_nsb_pageview',        'nsb_ajax_pageview' );
add_action( 'wp_ajax_nopriv_nsb_pageview', 'nsb_ajax_pageview' );
function nsb_ajax_pageview() {
    check_ajax_referer( 'nsb_pageview', 'nonce' );
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) { wp_send_json_error(); return; }

    // Rate limiting: 1 view per IP per post per hour
    $ip       = preg_replace( '/[^0-9a-fA-F.:,]/', '', $_SERVER['REMOTE_ADDR'] ?? '' );
    $rate_key = 'nsb_pv_' . md5( $ip . '_' . $post_id );
    if ( get_transient( $rate_key ) ) {
        wp_send_json_success(); return;
    }
    set_transient( $rate_key, 1, HOUR_IN_SECONDS );

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}nsb_pageviews (post_id, count)
         VALUES (%d, 1)
         ON DUPLICATE KEY UPDATE count = count + 1",
        $post_id
    ) );
    wp_send_json_success();
}

// -----------------------------------------------------------------------
// HTML output
// -----------------------------------------------------------------------

function nsb_render( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    if ( ! $post_id ) return '';

    $url     = urlencode( get_permalink( $post_id ) );
    $title   = urlencode( get_the_title( $post_id ) );
    $total   = nsb_get_view_count( $post_id );
    // Format as 1.2k, 3.4M etc.
    if ( $total >= 1000000 ) {
        $total_f = round( $total / 1000000, 1 ) . 'M';
    } elseif ( $total >= 1000 ) {
        $total_f = round( $total / 1000, 1 ) . 'k';
    } else {
        $total_f = (string) $total;
    }

    $networks = [
        'facebook' => [
            'label'   => 'Facebook',
            'color'   => '#1877F2',
            'share'   => "https://www.facebook.com/sharer/sharer.php?u={$url}",
            'tracked' => false,
            'icon'    => nsb_icon_facebook(),
        ],
        'x' => [
            'label'   => '',
            'color'   => '#000000',
            'share'   => "https://x.com/intent/tweet?url={$url}&text={$title}",
            'tracked' => true,
            'icon'    => nsb_icon_x(),
        ],
        'pinterest' => [
            'label'   => 'Pinterest',
            'color'   => '#E60023',
            'share'   => "https://pinterest.com/pin/create/button/?url={$url}&description={$title}",
            'tracked' => false,
            'icon'    => nsb_icon_pinterest(),
        ],
        'linkedin' => [
            'label'   => 'LinkedIn',
            'color'   => '#0A66C2',
            'share'   => "https://www.linkedin.com/sharing/share-offsite/?url={$url}",
            'tracked' => true,
            'icon'    => nsb_icon_linkedin(),
        ],
        'line' => [
            'label'   => 'LINE',
            'color'   => '#06C755',
            'share'   => "https://social-plugins.line.me/lineit/share?url={$url}",
            'tracked' => true,
            'icon'    => nsb_icon_line(),
        ],
    ];

    ob_start();
    ?>
    <div class="nsb-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>">
        <div class="nsb-total">
            <span class="nsb-total-number"><?php echo esc_html( $total_f ); ?></span>
            <span class="nsb-total-label">VIEWS</span>
        </div>
        <div class="nsb-buttons">
            <?php foreach ( $networks as $key => $net ) : ?>
            <a class="nsb-btn nsb-btn--<?php echo esc_attr( $key ); ?>"
               href="<?php echo esc_url( $net['share'] ); ?>"
               target="_blank"
               rel="noopener noreferrer"
               style="background:<?php echo esc_attr( $net['color'] ); ?>"
               <?php if ( $net['tracked'] ) : ?>
               data-network="<?php echo esc_attr( $key ); ?>"
               <?php endif; ?>
               aria-label="Share on <?php echo esc_attr( $net['label'] ); ?>">
                <?php echo $net['icon']; ?>
                <span class="nsb-btn-label"><?php echo esc_html( $net['label'] ); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// -----------------------------------------------------------------------
// Auto-insert before content
// -----------------------------------------------------------------------

add_filter( 'the_content', 'nsb_auto_insert' );
function nsb_auto_insert( $content ) {
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $content;
    $options = get_option( 'nsb_options', [] );
    if ( ! empty( $options['disable_auto'] ) ) return $content;
    return nsb_render() . $content;
}

// -----------------------------------------------------------------------
// Shortcode  [nat_share]
// -----------------------------------------------------------------------

add_shortcode( 'nat_share', function( $atts ) {
    $atts = shortcode_atts( [ 'id' => get_the_ID() ], $atts );
    return nsb_render( absint( $atts['id'] ) );
} );

// -----------------------------------------------------------------------
// SVG icons (inline, no external requests)
// -----------------------------------------------------------------------

function nsb_icon_facebook() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.413c0-3.022 1.792-4.691 4.533-4.691 1.313 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.928-1.956 1.88v2.257h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>';
}
function nsb_icon_x() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.748l7.73-8.835L1.254 2.25H8.08l4.259 5.63 5.905-5.63zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
}
function nsb_icon_pinterest() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>';
}
function nsb_icon_linkedin() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>';
}
function nsb_icon_line() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>';
}

// -----------------------------------------------------------------------
// Migration: Mashshare -> _nsb_seed_count
// -----------------------------------------------------------------------

add_action( 'wp_ajax_nsb_migrate',     'nsb_ajax_migrate' );
add_action( 'wp_ajax_nsb_detect_keys', 'nsb_ajax_detect_keys' );

function nsb_ajax_migrate() {
    check_ajax_referer( 'nsb_migrate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    // sanitize_text_field preserves leading underscores (sanitize_key would strip them)
    $meta_key = sanitize_text_field( wp_unslash( $_POST['meta_key'] ?? '_mashsb_shares' ) );
    $dry_run  = ! empty( $_POST['dry_run'] );

    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > 0",
        $meta_key
    ) );

    if ( empty( $rows ) ) {
        wp_send_json_success( [ 'count' => 0, 'message' => sprintf( __( "meta_key '%s' was not found.", 'nat-share-buttons' ), $meta_key ) ] );
    }

    $migrated = 0;
    foreach ( $rows as $row ) {
        $seed = absint( $row->meta_value );
        if ( ! $seed ) continue;
        if ( ! $dry_run ) {
            update_post_meta( (int) $row->post_id, '_nsb_seed_count', $seed );
        }
        $migrated++;
    }

    wp_send_json_success( [
        'count'   => $migrated,
        'dry_run' => $dry_run,
        'message' => $dry_run
            ? sprintf( __( 'Dry run: %d posts will be migrated.', 'nat-share-buttons' ), $migrated )
            : sprintf( __( 'Done! %d posts migrated.', 'nat-share-buttons' ), $migrated ),
    ] );
}

function nsb_ajax_detect_keys() {
    check_ajax_referer( 'nsb_migrate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    global $wpdb;
    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s
             ORDER BY meta_key",
            '%mash%'
        )
    );
    wp_send_json_success( [ 'keys' => $keys ] );
}

// -----------------------------------------------------------------------
// Settings page
// -----------------------------------------------------------------------

add_action( 'admin_menu', function() {
    add_options_page(
        'NAT Share Buttons',
        'NAT Share Buttons',
        'manage_options',
        'nat-share-buttons',
        'nsb_settings_page'
    );
} );

add_action( 'admin_init', function() {
    register_setting( 'nsb_options_group', 'nsb_options', [
        'sanitize_callback' => function( $input ) {
            return [
                'disable_auto' => ! empty( $input['disable_auto'] ) ? 1 : 0,
            ];
        }
    ] );
} );

function nsb_settings_page() {
    $options = get_option( 'nsb_options', [] );
    $nonce   = wp_create_nonce( 'nsb_migrate' );
    $i18n    = [
        'searching'    => __( 'Searching...', 'nat-share-buttons' ),
        'keys_found'   => __( 'Keys found: ', 'nat-share-buttons' ),
        'no_keys'      => __( 'No Mashshare meta keys found.', 'nat-share-buttons' ),
        'running'      => __( 'Running...', 'nat-share-buttons' ),
        'error'        => __( 'An error occurred.', 'nat-share-buttons' ),
    ];
    ?>
    <div class="wrap">
        <h1>NAT Share Buttons</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'nsb_options_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( 'Auto Insert', 'nat-share-buttons' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="nsb_options[disable_auto]" value="1"
                                <?php checked( 1, $options['disable_auto'] ?? 0 ); ?> />
                            <?php _e( 'Disable auto-insertion above post content (use shortcode <code>[nat_share]</code> for manual placement)', 'nat-share-buttons' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2><?php _e( 'Migrate from Mashshare', 'nat-share-buttons' ); ?></h2>
        <p><?php _e( 'Saves old Mashshare share counts as <code>_nsb_seed_count</code>. These are added to the page view count and shown as the total.', 'nat-share-buttons' ); ?></p>

        <table class="form-table">
            <tr>
                <th scope="row"><?php _e( 'Step 1', 'nat-share-buttons' ); ?></th>
                <td>
                    <button id="nsb-detect" class="button"><?php _e( 'Detect Mashshare meta keys', 'nat-share-buttons' ); ?></button>
                    <span id="nsb-detect-result" style="margin-left:10px;color:#666;"></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e( 'Step 2: Meta key', 'nat-share-buttons' ); ?></th>
                <td>
                    <input type="text" id="nsb-meta-key" value="_mashsb_shares" class="regular-text" />
                    <p class="description"><?php _e( 'Enter the key detected above (usually <code>_mashsb_shares</code>)', 'nat-share-buttons' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e( 'Step 3', 'nat-share-buttons' ); ?></th>
                <td>
                    <button id="nsb-dryrun" class="button"><?php _e( 'Dry run (count only)', 'nat-share-buttons' ); ?></button>
                    <button id="nsb-migrate" class="button button-primary" style="margin-left:8px;"><?php _e( 'Run migration', 'nat-share-buttons' ); ?></button>
                    <div id="nsb-migrate-result" style="margin-top:10px;font-weight:bold;"></div>
                </td>
            </tr>
        </table>

        <script>
        (function($){
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var i18n    = <?php echo wp_json_encode( $i18n ); ?>;

            $('#nsb-detect').on('click', function(e){
                e.preventDefault();
                $('#nsb-detect-result').text(i18n.searching);
                $.post(ajaxurl, { action:'nsb_detect_keys', nonce:nonce }, function(res){
                    if(res.success && res.data.keys.length){
                        $('#nsb-detect-result').text(i18n.keys_found + res.data.keys.join(', '));
                        $('#nsb-meta-key').val(res.data.keys[0]);
                    } else {
                        $('#nsb-detect-result').text(i18n.no_keys);
                    }
                });
            });

            function doMigrate(dry){
                var key = $('#nsb-meta-key').val();
                $('#nsb-migrate-result').text(i18n.running);
                $.post(ajaxurl, {
                    action:   'nsb_migrate',
                    nonce:    nonce,
                    meta_key: key,
                    dry_run:  dry ? 1 : 0,
                }, function(res){
                    if(res.success){
                        $('#nsb-migrate-result').css('color', dry ? '#666' : '#0a0').text(res.data.message);
                    } else {
                        $('#nsb-migrate-result').css('color','red').text(i18n.error);
                    }
                });
            }

            $('#nsb-dryrun').on('click',  function(e){ e.preventDefault(); doMigrate(true);  });
            $('#nsb-migrate').on('click', function(e){ e.preventDefault(); doMigrate(false); });
        })(jQuery);
        </script>

        <hr>
        <h2><?php _e( 'Usage', 'nat-share-buttons' ); ?></h2>
        <p><?php _e( 'By default, buttons are automatically displayed above each post\'s content.', 'nat-share-buttons' ); ?></p>
        <p><?php _e( 'To display at a specific location:', 'nat-share-buttons' ); ?> <code>[nat_share]</code></p>
        <p><?php _e( 'To call from a template:', 'nat-share-buttons' ); ?> <code>&lt;?php echo nsb_render(); ?&gt;</code></p>
    </div>
    <?php
}
