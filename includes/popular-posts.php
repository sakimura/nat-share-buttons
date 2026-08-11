<?php
/**
 * Local popular-posts module for NAT Share Buttons.
 *
 * The standalone NAT Local Popular Posts plugin owns these responsibilities
 * while NLPP_VERSION is defined. This file is loaded only after that guard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NSB_POPULAR_CONTEXT_FILTERS', true );

function nsb_filter_popular_posts( $posts, $count, $days, $include_pages ) {
    $filtered = apply_filters( 'nsb_popular_posts', $posts, $count, $days, $include_pages );
    return is_array( $filtered ) ? array_values( $filtered ) : array();
}

function nsb_filter_popular_item_url( $url, $post_id ) {
    $filtered = apply_filters( 'nsb_popular_item_url', $url, $post_id );
    return is_scalar( $filtered ) ? (string) $filtered : (string) $url;
}

function nsb_filter_popular_item_title( $title, $post_id ) {
    $filtered = apply_filters( 'nsb_popular_item_title', $title, $post_id );
    return is_scalar( $filtered ) ? (string) $filtered : (string) $title;
}

function nsb_ensure_popular_posts_schedule() {
    if ( ! get_option( 'nlpp_activated_at' ) ) {
        update_option( 'nlpp_activated_at', time(), false );
    }

    if ( ! wp_next_scheduled( 'nlpp_daily_cleanup' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nlpp_daily_cleanup' );
    }
}

/**
 * Increment the daily bucket for a page view accepted by the main handler.
 */
function nsb_increment_daily_pageview( $post_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'nsb_pageviews_daily';
    $date  = current_time( 'Y-m-d' );

    return $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table} (post_id, view_date, count)
             VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $post_id,
            $date
        )
    );
}

add_action( 'nlpp_daily_cleanup', 'nsb_cleanup_daily_views' );

function nsb_cleanup_daily_views() {
    global $wpdb;

    $table  = $wpdb->prefix . 'nsb_pageviews_daily';
    $cutoff = wp_date( 'Y-m-d', time() - 32 * DAY_IN_SECONDS, wp_timezone() );

    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE view_date < %s", $cutoff ) );
}

function nsb_popular_posts_table_exists( $table ) {
    global $wpdb;

    $like = $wpdb->esc_like( $table );
    return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
}

function nsb_get_popular_posts( $count = 10, $days = 2, $include_pages = true ) {
    global $wpdb;

    $count          = max( 1, min( 20, absint( $count ) ) );
    $days           = max( 1, min( 10, absint( $days ) ) );
    $post_types     = $include_pages ? array( 'post', 'page' ) : array( 'post' );
    $type_holders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $daily_table    = $wpdb->prefix . 'nsb_pageviews_daily';
    $lifetime_table = $wpdb->prefix . 'nsb_pageviews';
    $front_page_id  = absint( get_option( 'page_on_front', 0 ) );
    $front_page_sql = $front_page_id ? ' AND p.ID <> %d' : '';
    $activated_at   = (int) get_option( 'nlpp_activated_at', time() );
    $is_warm        = time() - $activated_at >= 2 * DAY_IN_SECONDS;

    if ( $is_warm && nsb_popular_posts_table_exists( $daily_table ) ) {
        $start_date = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS, wp_timezone() );
        $sql        = "SELECT p.ID, SUM(v.count) AS score
            FROM {$wpdb->posts} p
            INNER JOIN {$daily_table} v ON v.post_id = p.ID
            WHERE p.post_status = 'publish'
              AND p.post_type IN ({$type_holders})
              {$front_page_sql}
              AND v.view_date >= %s
            GROUP BY p.ID
            ORDER BY score DESC, p.ID DESC
            LIMIT %d";
        $params     = array_merge( $post_types, $front_page_id ? array( $front_page_id ) : array(), array( $start_date, $count ) );
        $rows       = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( ! empty( $rows ) ) {
            return nsb_filter_popular_posts( $rows, $count, $days, $include_pages );
        }
    }

    if ( ! nsb_popular_posts_table_exists( $lifetime_table ) ) {
        return nsb_filter_popular_posts( array(), $count, $days, $include_pages );
    }

    // Existing installations have lifetime totals but no historical buckets.
    // Use lifetime totals during warm-up, or if the recent window is empty.
    $sql    = "SELECT p.ID,
            COALESCE(v.count, 0) + COALESCE(seed.seed_count, 0) AS score
        FROM {$wpdb->posts} p
        LEFT JOIN {$lifetime_table} v ON v.post_id = p.ID
        LEFT JOIN (
            SELECT post_id, MAX(CAST(meta_value AS UNSIGNED)) AS seed_count
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_nsb_seed_count'
            GROUP BY post_id
        ) seed ON seed.post_id = p.ID
        WHERE p.post_status = 'publish'
          AND p.post_type IN ({$type_holders})
          {$front_page_sql}
          AND (COALESCE(v.count, 0) + COALESCE(seed.seed_count, 0)) > 0
        ORDER BY score DESC, p.ID DESC
        LIMIT %d";
    $params = array_merge( $post_types, $front_page_id ? array( $front_page_id ) : array(), array( $count ) );
    return nsb_filter_popular_posts( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), $count, $days, $include_pages );
}

function nsb_local_thumbnail_url( $post_id ) {
    $attachment_id = get_post_thumbnail_id( $post_id );
    if ( ! $attachment_id ) {
        return '';
    }

    $metadata = wp_get_attachment_metadata( $attachment_id );
    $file     = get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( ! $file ) {
        return '';
    }

    if ( ! empty( $metadata['sizes']['thumbnail']['file'] ) ) {
        $file = trailingslashit( dirname( $file ) ) . $metadata['sizes']['thumbnail']['file'];
    }

    $uploads = wp_get_upload_dir();
    return trailingslashit( $uploads['baseurl'] ) . ltrim( $file, '/' );
}

add_action( 'widgets_init', function() {
    register_widget( 'NSB_Popular_Posts_Widget' );
} );

class NSB_Popular_Posts_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'nat_local_popular',
            __( 'NAT Local Popular Posts', 'nat-share-buttons' ),
            array( 'description' => __( 'Popular posts calculated locally from NAT Share Buttons views.', 'nat-share-buttons' ) )
        );
    }

    public function widget( $args, $instance ) {
        $title         = ! empty( $instance['title'] ) ? $instance['title'] : __( '人気の投稿とページ', 'nat-share-buttons' );
        $count         = isset( $instance['count'] ) ? absint( $instance['count'] ) : 10;
        $days          = isset( $instance['days'] ) ? absint( $instance['days'] ) : 2;
        $include_pages = ! empty( $instance['include_pages'] );
        $show_images   = ! empty( $instance['show_images'] );
        $posts         = nsb_get_popular_posts( $count, $days, $include_pages );

        if ( empty( $posts ) ) {
            return;
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<ul class="nlpp-list">';
        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;
            $url     = nsb_filter_popular_item_url( get_permalink( $post_id ), $post_id );
            $name    = nsb_filter_popular_item_title( get_the_title( $post_id ), $post_id );
            $image   = $show_images ? nsb_local_thumbnail_url( $post_id ) : '';
            echo '<li class="nlpp-item">';
            echo '<a class="nlpp-link" href="' . esc_url( $url ) . '">';
            if ( $image ) {
                echo '<img class="nlpp-thumbnail" src="' . esc_url( $image ) . '" alt="" width="40" height="40" loading="lazy">';
            }
            echo '<span class="nlpp-title">' . esc_html( $name ) . '</span>';
            echo '</a></li>';
        }
        echo '</ul>';
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function form( $instance ) {
        $title         = $instance['title'] ?? __( '人気の投稿とページ', 'nat-share-buttons' );
        $count         = isset( $instance['count'] ) ? absint( $instance['count'] ) : 10;
        $days          = isset( $instance['days'] ) ? absint( $instance['days'] ) : 2;
        $include_pages = ! empty( $instance['include_pages'] );
        $show_images   = ! empty( $instance['show_images'] );
        ?>
        <p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'nat-share-buttons' ); ?></label>
        <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>"></p>
        <p><label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number of posts:', 'nat-share-buttons' ); ?></label>
        <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" max="20" value="<?php echo esc_attr( $count ); ?>"></p>
        <p><label for="<?php echo esc_attr( $this->get_field_id( 'days' ) ); ?>"><?php esc_html_e( 'Recent days:', 'nat-share-buttons' ); ?></label>
        <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'days' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'days' ) ); ?>" type="number" min="1" max="10" value="<?php echo esc_attr( $days ); ?>"></p>
        <p><label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'include_pages' ) ); ?>" value="1" <?php checked( $include_pages ); ?>> <?php esc_html_e( 'Include pages', 'nat-share-buttons' ); ?></label></p>
        <p><label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_images' ) ); ?>" value="1" <?php checked( $show_images ); ?>> <?php esc_html_e( 'Show local thumbnails', 'nat-share-buttons' ); ?></label></p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return array(
            'title'         => sanitize_text_field( $new_instance['title'] ?? '' ),
            'count'         => max( 1, min( 20, absint( $new_instance['count'] ?? 10 ) ) ),
            'days'          => max( 1, min( 10, absint( $new_instance['days'] ?? 2 ) ) ),
            'include_pages' => ! empty( $new_instance['include_pages'] ) ? 1 : 0,
            'show_images'   => ! empty( $new_instance['show_images'] ) ? 1 : 0,
        );
    }
}

add_action( 'wp_enqueue_scripts', function() {
    if ( is_active_widget( false, false, 'nat_local_popular', true ) ) {
        wp_enqueue_style( 'nat-local-popular-posts', NSB_PLUGIN_URL . 'assets/popular.css', array(), NSB_VERSION );
    }
} );
