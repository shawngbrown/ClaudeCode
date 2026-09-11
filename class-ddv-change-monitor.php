<?php
/**
 * DDV Change Monitor (v2 -- header-based)
 *
 * v1 attempted scoped text extraction from source pages, but testing
 * against statutes.capitol.texas.gov revealed the site is a JavaScript
 * (Angular) single-page application -- a plain server-side fetch never
 * sees the real statute text, only an empty app shell. Full-content
 * scraping was also flagged as contrary to the Texas Legislative
 * Council's own stated policy against automated data mining of their
 * site, with an explicit warning that persistent offenders get blocked.
 *
 * v2 instead compares Last-Modified / ETag response headers via a
 * lightweight HEAD request. CONFIRMED 2026-09-09 directly against the
 * live statutes.capitol.texas.gov server: both headers are genuinely
 * present. This sidesteps the JS-rendering problem entirely (headers
 * are set server-side regardless of what the front-end app does), and
 * a HEAD request downloads zero page content -- a far smaller, more
 * respectful footprint than the content-scraping approach v1 required.
 *
 * Tradeoff: this gives chapter-level granularity, not section-level.
 * A header change means "something in this chapter's page changed" --
 * every ddv_reference_fact sharing that source_url gets flagged
 * together, and a human reads the actual chapter to see what moved.
 * That is an acceptable, honest tradeoff given the alternative did
 * not actually work.
 *
 * Deploy: save as
 *   wp-content/plugins/docdocvault-core/includes/class-ddv-change-monitor.php
 * (overwrites the v1 file already required from DocDocVault_Core.php --
 * no change needed to the require line itself).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
// META FIELDS on ddv_reference_fact for monitoring state
// ─────────────────────────────────────────────
function ddv_register_change_monitor_meta() {
    $string_fields = [
        'last_known_modified',   // stored Last-Modified header value
        'last_known_etag',       // stored ETag header value
        'last_checked_date',
        'pending_modified',      // a differing Last-Modified seen once, awaiting confirmation
        'pending_etag',
        'pending_first_detected',
        'review_status',         // clean | pending_second_check | needs_human_review | no_headers_available
    ];

    foreach ( $string_fields as $key ) {
        register_post_meta( 'ddv_reference_fact', $key, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
}
add_action( 'init', 'ddv_register_change_monitor_meta' );

// ─────────────────────────────────────────────
// CRON SCHEDULE -- weekly interval (WP core has none by default)
// ─────────────────────────────────────────────
function ddv_add_weekly_cron_schedule( $schedules ) {
    $schedules['ddv_weekly'] = [
        'interval' => 7 * DAY_IN_SECONDS,
        'display'  => 'Once Weekly (DDV)',
    ];
    return $schedules;
}
add_filter( 'cron_schedules', 'ddv_add_weekly_cron_schedule' );

function ddv_maybe_schedule_change_monitor() {
    if ( ! wp_next_scheduled( 'ddv_run_change_monitor' ) ) {
        wp_schedule_event( time(), 'ddv_weekly', 'ddv_run_change_monitor' );
    }
}
add_action( 'wp', 'ddv_maybe_schedule_change_monitor' );

add_action( 'ddv_run_change_monitor', 'ddv_run_change_monitor_check' );

// ─────────────────────────────────────────────
// CORE CHECK FUNCTION
// Manual test:  wp eval 'print_r(ddv_run_change_monitor_check());'
// ─────────────────────────────────────────────
function ddv_run_change_monitor_check() {
    $facts = get_posts( [
        'post_type'      => 'ddv_reference_fact',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ] );

    // Cache HEAD results per URL within this run -- multiple facts
    // commonly share the same source_url (e.g. several sections in
    // the same chapter), so this avoids redundant requests to the
    // same server in a single pass.
    $url_header_cache = [];
    $results = [];

    foreach ( $facts as $fact ) {
        $results[ $fact->ID ] = ddv_check_single_fact( $fact->ID, $url_header_cache );
    }

    return $results;
}

function ddv_check_single_fact( $post_id, &$url_header_cache ) {
    $source_type = get_post_meta( $post_id, 'source_type', true );
    $source_url  = get_post_meta( $post_id, 'source_url', true );

    if ( $source_type === 'client_supplied' || empty( $source_url ) ) {
        return 'skipped_not_monitorable';
    }

    if ( ! isset( $url_header_cache[ $source_url ] ) ) {
        $response = wp_remote_head( $source_url, [
            'timeout'    => 20,
            'user-agent' => 'DocDocVault Compliance Monitor (docdocvault.com)',
        ] );

        if ( is_wp_error( $response ) ) {
            $url_header_cache[ $source_url ] = false; // mark as failed, cached for this run
        } else {
            $url_header_cache[ $source_url ] = [
                'last_modified' => wp_remote_retrieve_header( $response, 'last-modified' ),
                'etag'          => wp_remote_retrieve_header( $response, 'etag' ),
            ];
        }
    }

    $cached = $url_header_cache[ $source_url ];

    update_post_meta( $post_id, 'last_checked_date', current_time( 'mysql' ) );

    if ( $cached === false ) {
        // A fetch failure is NOT a change -- do not touch stored
        // headers, just note the failed attempt and retry next run.
        return 'fetch_failed';
    }

    $new_modified = $cached['last_modified'];
    $new_etag     = $cached['etag'];

    if ( empty( $new_modified ) && empty( $new_etag ) ) {
        update_post_meta( $post_id, 'review_status', 'no_headers_available' );
        return 'no_headers_available';
    }

    $last_modified = get_post_meta( $post_id, 'last_known_modified', true );
    $last_etag     = get_post_meta( $post_id, 'last_known_etag', true );
    $pending_modified = get_post_meta( $post_id, 'pending_modified', true );
    $pending_etag     = get_post_meta( $post_id, 'pending_etag', true );

    // First-ever check -- establish baseline.
    if ( empty( $last_modified ) && empty( $last_etag ) ) {
        update_post_meta( $post_id, 'last_known_modified', $new_modified );
        update_post_meta( $post_id, 'last_known_etag', $new_etag );
        update_post_meta( $post_id, 'review_status', 'clean' );
        return 'baseline_established';
    }

    $unchanged = ( $new_modified === $last_modified ) && ( $new_etag === $last_etag );

    if ( $unchanged ) {
        update_post_meta( $post_id, 'pending_modified', '' );
        update_post_meta( $post_id, 'pending_etag', '' );
        update_post_meta( $post_id, 'pending_first_detected', '' );
        update_post_meta( $post_id, 'review_status', 'clean' );
        return 'no_change';
    }

    // ── MULTI-SIGNAL CONFIRMATION ──
    // Headers are a much cleaner signal than content hashing, but a
    // server redeploy can sometimes regenerate an ETag without real
    // content changing -- still worth confirming on a second check
    // before escalating to a human.
    $pending_matches_new = ( $new_modified === $pending_modified ) && ( $new_etag === $pending_etag );

    if ( empty( $pending_modified ) && empty( $pending_etag ) ) {
        update_post_meta( $post_id, 'pending_modified', $new_modified );
        update_post_meta( $post_id, 'pending_etag', $new_etag );
        update_post_meta( $post_id, 'pending_first_detected', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'review_status', 'pending_second_check' );
        return 'pending_first_detection';
    }

    if ( $pending_matches_new ) {
        update_post_meta( $post_id, 'review_status', 'needs_human_review' );
        return 'change_confirmed_needs_review';
    }

    // Third distinct reading -- unstable signal, reset pending rather
    // than escalate on unreliable data.
    update_post_meta( $post_id, 'pending_modified', $new_modified );
    update_post_meta( $post_id, 'pending_etag', $new_etag );
    update_post_meta( $post_id, 'pending_first_detected', current_time( 'mysql' ) );
    update_post_meta( $post_id, 'review_status', 'pending_second_check' );
    return 'unstable_source_pending_reset';
}

// ─────────────────────────────────────────────
// ADMIN ACTION: human confirms a change is real
// ─────────────────────────────────────────────
function ddv_confirm_fact_change( $post_id ) {
    $pending_modified = get_post_meta( $post_id, 'pending_modified', true );
    $pending_etag     = get_post_meta( $post_id, 'pending_etag', true );

    if ( empty( $pending_modified ) && empty( $pending_etag ) ) {
        return false;
    }

    update_post_meta( $post_id, 'last_known_modified', $pending_modified );
    update_post_meta( $post_id, 'last_known_etag', $pending_etag );
    update_post_meta( $post_id, 'pending_modified', '' );
    update_post_meta( $post_id, 'pending_etag', '' );
    update_post_meta( $post_id, 'pending_first_detected', '' );
    update_post_meta( $post_id, 'review_status', 'clean' );
    // Deliberately NOT wired to any customer-facing notification --
    // per Addendum 5, that is a separate, explicit step taken after
    // this human confirmation, never an automatic consequence of it.
    return true;
}

// ─────────────────────────────────────────────
// ADMIN COLUMN
// ─────────────────────────────────────────────
function ddv_reference_fact_monitor_column( $columns ) {
    $columns['review_status'] = 'Monitor Status';
    return $columns;
}
add_filter( 'manage_ddv_reference_fact_posts_columns', 'ddv_reference_fact_monitor_column' );

function ddv_reference_fact_monitor_column_content( $column, $post_id ) {
    if ( $column === 'review_status' ) {
        $status = get_post_meta( $post_id, 'review_status', true );
        echo esc_html( $status ?: 'not yet checked' );
    }
}
add_action( 'manage_ddv_reference_fact_posts_custom_column', 'ddv_reference_fact_monitor_column_content', 10, 2 );