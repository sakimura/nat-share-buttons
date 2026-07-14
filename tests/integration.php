<?php

/**
 * Lightweight integration checks for the page-view migration paths.
 * Run with: php tests/integration.php integrated|standalone
 */

$mode = $argv[1] ?? 'integrated';
if ( ! in_array( $mode, array( 'integrated', 'standalone' ), true ) ) {
    fwrite( STDERR, "Unknown mode\n" );
    exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
if ( 'standalone' === $mode ) {
    define( 'NLPP_VERSION', '1.0.0' );
}

$GLOBALS['test_actions']    = array();
$GLOBALS['test_options']    = array( 'nsb_db_version' => '4', 'nlpp_activated_at' => 1 );
$GLOBALS['test_transients'] = array();
$GLOBALS['test_scheduled']  = array();

class Test_Json_Response extends Exception {
    public $success;
    public $status;

    public function __construct( $success, $status ) {
        parent::__construct();
        $this->success = $success;
        $this->status  = $status;
    }
}

class Test_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $lifetime = array();
    public $daily = array();
    public $fail_daily = false;
    public $lock_depth = 0;
    public $engines = array(
        'wp_nsb_pageviews'       => 'InnoDB',
        'wp_nsb_pageviews_daily' => 'InnoDB',
    );
    private $snapshot;

    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
            $query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }
        return $query;
    }

    public function query( $query ) {
        if ( 'START TRANSACTION' === $query ) {
            $this->snapshot = array( $this->lifetime, $this->daily );
            return true;
        }
        if ( 'ROLLBACK' === $query ) {
            list( $this->lifetime, $this->daily ) = $this->snapshot;
            return true;
        }
        if ( 'COMMIT' === $query ) {
            $this->snapshot = null;
            return true;
        }
        if ( preg_match( '/^ALTER TABLE ([A-Za-z0-9_]+) ENGINE=InnoDB$/', $query, $matches ) ) {
            $this->engines[ $matches[1] ] = 'InnoDB';
            return true;
        }
        if ( false !== strpos( $query, 'nsb_pageviews_daily' ) ) {
            if ( $this->fail_daily ) {
                $this->last_error = 'simulated daily failure';
                return false;
            }
            $post_id = (int) ( $_POST['post_id'] ?? 0 );
            $this->daily[ $post_id ] = ( $this->daily[ $post_id ] ?? 0 ) + 1;
            return 1;
        }
        if ( false !== strpos( $query, 'nsb_pageviews' ) ) {
            $post_id = (int) ( $_POST['post_id'] ?? 0 );
            $this->lifetime[ $post_id ] = ( $this->lifetime[ $post_id ] ?? 0 ) + 1;
            return 1;
        }
        return true;
    }

    public function get_var( $query ) {
        if ( false !== strpos( $query, 'GET_LOCK' ) ) {
            ++$this->lock_depth;
            return 1;
        }
        if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
            --$this->lock_depth;
            return 1;
        }
        if ( false !== strpos( $query, 'information_schema.TABLES' ) ) {
            foreach ( $this->engines as $table => $engine ) {
                if ( false !== strpos( $query, "'{$table}'" ) ) {
                    return $engine;
                }
            }
            return null;
        }
        return null;
    }

    public function esc_like( $value ) {
        return $value;
    }
}

$wpdb = new Test_WPDB();

class WP_Widget {
    public $id_base;
    public function __construct( $id_base = '' ) { $this->id_base = $id_base; }
}

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/nat-share-buttons/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function add_filter( ...$args ) {}
function add_shortcode( ...$args ) {}
function add_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['test_actions'][ $hook ][ $priority ][] = $callback;
}
function test_run_hook( $hook ) {
    if ( empty( $GLOBALS['test_actions'][ $hook ] ) ) return;
    ksort( $GLOBALS['test_actions'][ $hook ] );
    foreach ( $GLOBALS['test_actions'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $callback ) call_user_func( $callback );
    }
}
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['test_options'][ $key ] = $value; return true; }
function wp_next_scheduled( $hook ) { return $GLOBALS['test_scheduled'][ $hook ] ?? false; }
function wp_schedule_event( $time, $recurrence, $hook ) { $GLOBALS['test_scheduled'][ $hook ] = $time; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['test_scheduled'][ $hook ] ); return 1; }
function wp_unslash( $value ) { return $value; }
function wp_salt( $scheme ) { return 'test-only-secret-salt'; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce; }
function check_ajax_referer( $action, $field ) {
    if ( ! wp_verify_nonce( $_POST[ $field ] ?? '', $action ) ) throw new Test_Json_Response( false, 403 );
}
function get_post( $post_id ) {
    if ( 99 === (int) $post_id ) return (object) array( 'post_status' => 'publish', 'post_type' => 'product' );
    return (object) array( 'post_status' => 'publish', 'post_type' => 'post' );
}
function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); return true; }
function wp_send_json_success( $data = null, $status = 200 ) { throw new Test_Json_Response( true, $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { throw new Test_Json_Response( false, $status ); }
function current_time( $format ) { return '2026-07-14'; }
function wp_date( $format, $timestamp, $timezone = null ) { return gmdate( $format, $timestamp ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function __( $text, $domain = null ) { return $text; }
function is_singular() { return true; }
function get_queried_object_id() { return 7; }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function admin_url( $path = '' ) { return 'https://example.test/blog/wp-admin/' . ltrim( $path, '/' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_create_nonce( $action ) { return 'valid'; }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['test_localized'][ $name ] = $data; }

require dirname( __DIR__ ) . '/nat-share-buttons.php';
test_run_hook( 'plugins_loaded' );
nsb_enqueue();

function test_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

function test_pageview( $post_id ) {
    $_POST = array( 'nonce' => 'valid', 'post_id' => $post_id );
    try {
        nsb_ajax_pageview_compat_preflight();
        nsb_ajax_pageview();
    } catch ( Test_Json_Response $response ) {
        return $response;
    }
    throw new RuntimeException( 'AJAX handler did not respond.' );
}

$_SERVER['REMOTE_ADDR'] = '192.0.2.44';

if ( 'integrated' === $mode ) {
    test_assert( function_exists( 'nsb_increment_daily_pageview' ), 'integrated module should load' );
    test_assert( ! function_exists( 'nlpp_get_popular_posts' ), 'integrated module must not block standalone reactivation with legacy function names' );
    test_assert( isset( $GLOBALS['test_scheduled']['nlpp_daily_cleanup'] ), 'cleanup cron should self-heal' );
    $widget = new NSB_Popular_Posts_Widget();
    test_assert( 'nat_local_popular' === $widget->id_base, 'widget id_base should preserve existing placement and options' );
    test_assert( '/blog/wp-admin/admin-ajax.php' === $GLOBALS['test_localized']['NSB']['ajax_path'], 'AJAX path should preserve a WordPress subdirectory' );

    $wpdb->engines = array_fill_keys( array_keys( $wpdb->engines ), 'MyISAM' );
    test_assert( ! nsb_ensure_transactional_pageview_tables(), 'runtime check should reject non-transactional tables' );
    test_assert( nsb_ensure_transactional_pageview_tables( true ), 'DB upgrade should convert page-view tables to InnoDB' );
    test_assert( array( 'InnoDB' ) === array_values( array_unique( $wpdb->engines ) ), 'both page-view tables should be transactional after upgrade' );

    $key = nsb_rate_limit_key( 'nsb_pv_', 'page-view', array( 1 ) );
    test_assert( 47 === strlen( $key ), 'HMAC transient key should use a 40-character digest' );
    test_assert( false === strpos( $key, $_SERVER['REMOTE_ADDR'] ), 'transient key must not expose the IP' );

    $response = test_pageview( 1 );
    test_assert( $response->success, 'first integrated view should succeed' );
    test_assert( 1 === $wpdb->lifetime[1] && 1 === $wpdb->daily[1], 'first integrated view should increment both counters' );
    test_assert( 0 === $wpdb->lock_depth, 'successful view should release its rate-limit lock' );

    test_pageview( 1 );
    test_assert( 1 === $wpdb->lifetime[1] && 1 === $wpdb->daily[1], 'rate-limited repeat should not increment either counter' );

    $legacy = nsb_legacy_rate_limit_key( 'nsb_pv_', array( 2 ) );
    set_transient( $legacy, 1, HOUR_IN_SECONDS );
    test_pageview( 2 );
    test_assert( empty( $wpdb->lifetime[2] ) && empty( $wpdb->daily[2] ), 'legacy transient should prevent an upgrade-time duplicate' );
    test_assert( get_transient( nsb_rate_limit_key( 'nsb_pv_', 'page-view', array( 2 ) ) ), 'legacy transient should be promoted to an HMAC key' );

    $wpdb->fail_daily = true;
    $response = test_pageview( 3 );
    test_assert( ! $response->success && 500 === $response->status, 'daily failure should return an error' );
    test_assert( empty( $wpdb->lifetime[3] ) && empty( $wpdb->daily[3] ), 'daily failure should roll back lifetime count' );
    test_assert( ! get_transient( nsb_rate_limit_key( 'nsb_pv_', 'page-view', array( 3 ) ) ), 'failed transaction should not rate-limit a retry' );
    test_assert( 0 === $wpdb->lock_depth, 'failed view should release its rate-limit lock' );

    nsb_deactivate();
    test_assert( ! isset( $GLOBALS['test_scheduled']['nlpp_daily_cleanup'] ), 'integrated deactivation should clear its cleanup cron' );
} else {
    test_assert( ! function_exists( 'nsb_increment_daily_pageview' ), 'integrated module should stand down' );

    $_POST = array( 'nonce' => 'valid', 'post_id' => 4 );
    $wpdb->daily[4] = 1; // The standalone priority-1 handler runs first.
    $response = test_pageview( 4 );
    test_assert( $response->success, 'standalone compatibility request should succeed' );
    test_assert( 1 === $wpdb->lifetime[4] && 1 === $wpdb->daily[4], 'compatibility path should increment each counter once' );

    test_pageview( 4 );
    test_assert( 1 === $wpdb->lifetime[4] && 1 === $wpdb->daily[4], 'shared legacy transient should prevent a repeat' );

    $response = test_pageview( 99 );
    test_assert( ! $response->success && 400 === $response->status, 'preflight should reject unsupported post types before standalone counting' );

    $GLOBALS['test_scheduled']['nlpp_daily_cleanup'] = 123;
    nsb_deactivate();
    test_assert( isset( $GLOBALS['test_scheduled']['nlpp_daily_cleanup'] ), 'deactivation should leave standalone cleanup cron intact' );
}

echo "PASS: {$mode}\n";
