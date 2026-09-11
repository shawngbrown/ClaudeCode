<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Reconciliation
 *
 * DDV_Document posts are pointers — the real file content lives only
 * in Nextcloud. If a file gets touched OUTSIDE WordPress (Collabora,
 * direct Nextcloud access), WordPress's copy of the truth
 * (nc_last_modified, even whether the file still exists at all) can
 * silently go stale. This class's job is to detect that drift.
 *
 * Never silently auto-corrects and hides a problem, per this
 * project's standing never-autonomous-action principle: a mismatch
 * gets FLAGGED (reconciliation_status set to 'drifted' or 'missing')
 * for a human to see, not quietly fixed and forgotten. The one
 * exception: on 'drifted', nc_last_modified itself IS updated to the
 * new real value — the flag persists for review, but the stored
 * timestamp should reflect reality once checked, not lag behind it.
 *
 * Deliberately separate from compliance-checking (still unbuilt) even
 * though both read the same underlying stage/lifecycle concept — see
 * the design doc's Strategic Principles, item 2 (never collapse
 * genuinely separate mechanisms just because they share a reference
 * point).
 */
class DDV_Reconciliation {

    const CRON_HOOK = 'ddv_reconciliation_run';

    const STATUS_PENDING = 'pending';
    const STATUS_OK      = 'ok';
    const STATUS_DRIFTED = 'drifted';
    const STATUS_MISSING = 'missing';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'maybe_schedule_cron' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_batch' ] );

        // Reconciliation visibility (closing the "flag for human
        // review" loop — see class docblock) — a custom column and
        // quick-filter links on DDV_Document's own wp-admin list
        // table. Standard WP list-table hooks, nothing new invented.
        add_filter( 'manage_' . DDV_Document::POST_TYPE . '_posts_columns', [ __CLASS__, 'add_reconciliation_column' ] );
        add_action( 'manage_' . DDV_Document::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_reconciliation_column' ], 10, 2 );
        add_filter( 'views_edit-' . DDV_Document::POST_TYPE, [ __CLASS__, 'add_reconciliation_views' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_by_reconciliation_status' ] );
    }

    /**
     * Register the hourly WP-Cron schedule, if not already scheduled.
     * Safe to call on every page load — wp_next_scheduled() makes this
     * idempotent.
     */
    public static function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    /**
     * Check ONE document against the real Nextcloud file state.
     * Requires the document's tenant's Prime Admin to have real NC
     * credentials stored (same pattern used throughout
     * DDV_Vault_Connector) — returns false if they don't, without
     * marking anything (a credentials gap is not the same finding as
     * a real drift/missing result, and shouldn't be recorded as one).
     */
    public static function check_document( $document_post_id ) {
        $document_post_id = intval( $document_post_id );

        $tenant_id = get_post_meta( $document_post_id, 'tenant_id', true );
        $nc_path   = get_post_meta( $document_post_id, 'nc_path', true );

        if ( ! $tenant_id || ! $nc_path ) {
            return false;
        }

        $tenant = DDV_Tenant::get_tenant( $tenant_id );
        if ( ! $tenant ) {
            return false;
        }

        $prime_admin_id  = intval( $tenant['prime_admin_user_id'] );
        $nc_username     = get_user_meta( $prime_admin_id, 'ddv_nc_username', true );
        $nc_password_enc = get_user_meta( $prime_admin_id, 'ddv_nc_password_enc', true );

        if ( ! $nc_username || ! $nc_password_enc ) {
            error_log( "DDV_Reconciliation: cannot check document {$document_post_id} — tenant {$tenant_id} Prime Admin has no NC credentials." );
            return false;
        }

        $nc_password = DDV_Vault_Connector::decrypt_nc_password( $nc_password_enc );

        $real_last_modified = DDV_Vault_Connector::webdav_check_file( $nc_username, $nc_password, $nc_path );

        $now = current_time( 'mysql' );

        // Genuinely missing — a real 404, not a technical failure.
        if ( $real_last_modified === null ) {
            update_post_meta( $document_post_id, 'reconciliation_status', self::STATUS_MISSING );
            update_post_meta( $document_post_id, 'last_reconciled_at', $now );
            return self::STATUS_MISSING;
        }

        // Technical failure (connection error, unexpected response) —
        // do NOT mark as missing or drifted; leave the existing status
        // alone and let the next batch retry.
        if ( $real_last_modified === false ) {
            return false;
        }

        $stored_last_modified = get_post_meta( $document_post_id, 'nc_last_modified', true );

        if ( $stored_last_modified === $real_last_modified ) {
            update_post_meta( $document_post_id, 'reconciliation_status', self::STATUS_OK );
            update_post_meta( $document_post_id, 'last_reconciled_at', $now );
            return self::STATUS_OK;
        }

        // Drifted — the file was touched outside WordPress since we
        // last knew. Flag it AND bring the stored timestamp current,
        // per the class docblock's stated exception.
        update_post_meta( $document_post_id, 'reconciliation_status', self::STATUS_DRIFTED );
        update_post_meta( $document_post_id, 'nc_last_modified', $real_last_modified );
        update_post_meta( $document_post_id, 'last_reconciled_at', $now );

        return self::STATUS_DRIFTED;
    }

    /**
     * The actual WP-Cron job. Checks a batch of documents,
     * oldest-checked-first (never-checked documents, meta_value
     * empty, sort first). Deliberately batched rather than checking
     * every document at once — this does not scale for a growing
     * document count.
     *
     * Temporarily unhooks DDV_Document::scope_to_tenant_and_role()
     * around this query. That hook scopes to the CURRENT USER's
     * tenant — but WP-Cron runs with no logged-in user
     * (get_current_user_id() returns 0), which would silently filter
     * this system-level query down to zero results every time if the
     * hook weren't removed first.
     */
    public static function run_batch( $batch_size = 20 ) {
        remove_action( 'pre_get_posts', [ 'DDV_Document', 'scope_to_tenant_and_role' ] );

        $query = new WP_Query( [
            'post_type'      => DDV_Document::POST_TYPE,
            'posts_per_page' => $batch_size,
            'fields'         => 'ids',
            'meta_key'       => 'last_reconciled_at',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ] );

        add_action( 'pre_get_posts', [ 'DDV_Document', 'scope_to_tenant_and_role' ] );

        $results = [
            'checked' => 0,
            'ok'      => 0,
            'drifted' => 0,
            'missing' => 0,
            'skipped' => 0,
        ];

        foreach ( $query->posts as $document_post_id ) {
            $outcome = self::check_document( $document_post_id );
            $results['checked']++;

            if ( $outcome === self::STATUS_OK ) {
                $results['ok']++;
            } elseif ( $outcome === self::STATUS_DRIFTED ) {
                $results['drifted']++;
            } elseif ( $outcome === self::STATUS_MISSING ) {
                $results['missing']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Insert a "Reconciliation" column into DDV_Document's wp-admin
     * list table, right after the title.
     */
    public static function add_reconciliation_column( $columns ) {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( $key === 'title' ) {
                $new_columns['reconciliation'] = 'Reconciliation';
            }
        }

        return $new_columns;
    }

    /**
     * Render the Reconciliation column's content — a plain-language,
     * color-coded status. Never a raw "no" — a human scanning this
     * list should immediately see what needs attention without
     * decoding a status code.
     */
    public static function render_reconciliation_column( $column, $post_id ) {
        if ( $column !== 'reconciliation' ) {
            return;
        }

        $status = get_post_meta( $post_id, 'reconciliation_status', true );

        $labels = [
            self::STATUS_PENDING => [ '#666666', 'Not yet checked' ],
            self::STATUS_OK      => [ '#2e7d32', 'OK' ],
            self::STATUS_DRIFTED => [ '#e65100', 'Drifted — review' ],
            self::STATUS_MISSING => [ '#c62828', 'Missing — review' ],
        ];

        [ $color, $label ] = $labels[ $status ] ?? [ '#666666', 'Unknown' ];

        echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</strong>';
    }

    /**
     * Add quick-filter "views" (the same "All | Published | Trash"
     * style links native to every WP list table) for each
     * reconciliation status, with real counts, scoped to the current
     * user's own tenant — DDV_Document's own scope_to_tenant_and_role()
     * still applies normally here (this is a real logged-in admin
     * viewing their own tenant's documents, not the system-level
     * run_batch() job, which is the only place that hook gets
     * deliberately bypassed).
     */
    public static function add_reconciliation_views( $views ) {
        foreach ( [ self::STATUS_DRIFTED, self::STATUS_MISSING, self::STATUS_OK, self::STATUS_PENDING ] as $status ) {
            $count = new WP_Query( [
                'post_type'      => DDV_Document::POST_TYPE,
                'posts_per_page' => 1,
                'meta_key'       => 'reconciliation_status',
                'meta_value'     => $status,
                'fields'         => 'ids',
            ] );

            $label = ucfirst( $status );
            $url   = add_query_arg( [ 'post_type' => DDV_Document::POST_TYPE, 'reconciliation_status' => $status ], admin_url( 'edit.php' ) );

            $views[ 'reconciliation_' . $status ] = sprintf(
                '<a href="%s">%s <span class="count">(%d)</span></a>',
                esc_url( $url ),
                esc_html( $label ),
                $count->found_posts
            );
        }

        return $views;
    }

    /**
     * Actually apply the filter when one of the views links above is
     * clicked — reads the reconciliation_status URL param and scopes
     * the query to it.
     */
    public static function filter_by_reconciliation_status( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( $query->get( 'post_type' ) !== DDV_Document::POST_TYPE ) {
            return;
        }

        $status = sanitize_key( $_GET['reconciliation_status'] ?? '' );

        if ( ! $status ) {
            return;
        }

        $meta_query   = $query->get( 'meta_query' );
        $meta_query   = is_array( $meta_query ) ? $meta_query : [];
        $meta_query[] = [
            'key'   => 'reconciliation_status',
            'value' => $status,
        ];

        $query->set( 'meta_query', $meta_query );
    }
}