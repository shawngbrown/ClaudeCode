<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Client_Workspace
 *
 * One post = one client's folder/workspace (the per-client tree under a
 * tenant's S01 master folder — NOT the tenant's own master folder itself).
 *
 * A dedicated CPT rather than core "Posts": keeps client records out of
 * the blog/RSS/search-engine surface, and gets its own capability type
 * instead of sharing generic edit_posts with actual blogging.
 *
 * Uses a DEDICATED taxonomy pair (ddv_business_type / ddv_client_tag) —
 * deliberately separate from WordPress's built-in category/post_tag,
 * and separate from the compliance taxonomy (ddv_compliance_category /
 * ddv_document_type, a distinct system attached elsewhere) so none of
 * the three ever collide.
 *
 * tenant_id is the critical piece of meta on every post here. Every
 * query touching this CPT MUST be scoped to it via pre_get_posts —
 * without that, two tenants sharing one WP install could see each
 * other's client list, which would be a real data leak, not cosmetic.
 * Term names (business type, client name) are NOT relied on for
 * isolation — per the architecture doc, identity is enforced by the
 * tenant/client meta-ID chain, not by taxonomy term uniqueness.
 */
class DDV_Client_Workspace {

    const POST_TYPE     = 'ddv_client_workspace';
    const TAX_BUSINESS   = 'ddv_business_type';
    const TAX_CLIENT_TAG = 'ddv_client_tag';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'init', [ __CLASS__, 'register_taxonomies' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'scope_to_current_tenant' ] );
    }

    /**
     * Register the Client Workspace post type.
     */
    public static function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Client Workspaces',
                'singular_name' => 'Client Workspace',
                'add_new_item'  => 'Add New Client',
                'edit_item'     => 'Edit Client Workspace',
                'search_items'  => 'Search Clients',
                'not_found'     => 'No clients found.',
            ],
            'public'             => false, // never queried on the public front end directly by slug/archive
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'capability_type'    => [ 'ddv_client_workspace', 'ddv_client_workspaces' ],
            'map_meta_cap'       => true,
            'supports'           => [ 'title', 'custom-fields' ],
            'has_archive'        => false,
            'rewrite'            => false,
            'exclude_from_search' => true,
        ] );

        register_post_meta( self::POST_TYPE, 'tenant_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => false, // never exposed to the public REST API unfiltered
            'sanitize_callback' => 'absint',
        ] );

        register_post_meta( self::POST_TYPE, 'pcmf_code', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_post_meta( self::POST_TYPE, 'needs_entity_review', [
            'type'              => 'boolean',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ] );

        // Addendum 8: whether this client's Fine Schedule / Enforcement
        // Policy is registered with the county — a real, checkable legal
        // prerequisite (Texas Property Code Sec. 209 framework) for a
        // fine to actually be enforceable. Stored as a date string,
        // not a plain boolean, since "when was it registered" is
        // itself a useful, auditable fact — empty/unset means not
        // registered. Consistent with this project's standing
        // principle: check against the CLIENT's own stored status,
        // never assert what the law requires in general.
        register_post_meta( self::POST_TYPE, 'fine_schedule_county_registered_date', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    /**
     * Register the dedicated business-type / client-name taxonomy pair.
     */
    public static function register_taxonomies() {
        register_taxonomy( self::TAX_BUSINESS, self::POST_TYPE, [
            'labels' => [
                'name'          => 'Business Types',
                'singular_name' => 'Business Type',
            ],
            'hierarchical'  => true, // behaves like Category — a small, bounded set
            'show_ui'       => true,
            'show_in_rest'  => true,
            'public'        => false,
            'rewrite'       => false,
        ] );

        register_taxonomy( self::TAX_CLIENT_TAG, self::POST_TYPE, [
            'labels' => [
                'name'          => 'Client',
                'singular_name' => 'Client',
            ],
            'hierarchical'  => false, // behaves like Tag — flat, unbounded
            'show_ui'       => true,
            'show_in_rest'  => true,
            'public'        => false,
            'rewrite'       => false,
        ] );
    }

    /**
     * Create a new Client Workspace post for a given tenant.
     *
     * $args expects: client_name, business_type (a term name/slug in
     * ddv_business_type — the client's OWN entity type, independently
     * selected, not inherited from the parent tenant — per architecture
     * doc Section 3.2), and optionally needs_entity_review (bool).
     *
     * Returns the new post ID, or WP_Error.
     */
    public static function create_client( $tenant_id, array $args ) {
        $tenant_id = intval( $tenant_id );

        if ( empty( $tenant_id ) || ! DDV_Tenant::get_tenant( $tenant_id ) ) {
            return new WP_Error( 'ddv_client_invalid_tenant', 'A valid tenant_id is required.' );
        }

        $client_name = sanitize_text_field( $args['client_name'] ?? '' );
        if ( empty( $client_name ) ) {
            return new WP_Error( 'ddv_client_missing_name', 'client_name is required.' );
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_title'  => $client_name,
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'tenant_id', $tenant_id );

        if ( ! empty( $args['needs_entity_review'] ) ) {
            update_post_meta( $post_id, 'needs_entity_review', true );
        }

        // Generate and store the PCMF client code now that a real post ID
        // exists to use as the client segment.
        $pcmf_code = DDV_PCMF::client_code( $tenant_id, $post_id );
        if ( ! is_wp_error( $pcmf_code ) ) {
            update_post_meta( $post_id, 'pcmf_code', $pcmf_code );
        }

        if ( ! empty( $args['business_type'] ) ) {
            wp_set_object_terms( $post_id, sanitize_text_field( $args['business_type'] ), self::TAX_BUSINESS );
        }

        // The client-name taxonomy term mirrors the title for browsing/
        // filtering (Section: filter/browse layer). Identity/isolation is
        // still enforced by tenant_id meta, never by this term.
        wp_set_object_terms( $post_id, $client_name, self::TAX_CLIENT_TAG );

        return $post_id;
    }

    /**
     * THE enforcement point. Every query for this post type gets a
     * tenant_id meta clause automatically appended — callers cannot
     * accidentally omit it, and it cannot be bypassed by taxonomy/term
     * queries alone. Scopes to the current user's own tenant
     * (DDV_Tenant::get_tenant_by_user()); real WP administrators
     * (manage_options) are not restricted, matching the same exception
     * pattern used in DDV_Access_Control.
     */
    public static function scope_to_current_tenant( $query ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            // Let normal wp-admin screens work for real admins without
            // interference; the REST/front-end paths are what most
            // matter here. Still enforce for AJAX requests inside admin.
            if ( current_user_can( 'manage_options' ) ) {
                return;
            }
        }

        if ( $query->get( 'post_type' ) !== self::POST_TYPE ) {
            return;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        $tenant = DDV_Tenant::get_tenant_by_user( get_current_user_id() );

        $existing_meta_query = (array) $query->get( 'meta_query' );

        if ( ! $tenant ) {
            // No tenant on this account at all — force a query that can
            // never match anything, rather than silently returning
            // everyone's data or throwing an error.
            $existing_meta_query[] = [
                'key'   => 'tenant_id',
                'value' => 0,
            ];
        } else {
            $existing_meta_query[] = [
                'key'   => 'tenant_id',
                'value' => (int) $tenant['id'],
            ];
        }

        $query->set( 'meta_query', $existing_meta_query );
    }

    /**
     * Whether this client's Fine Schedule is registered with the
     * county (Addendum 8). Returns true only if a real, non-empty
     * registration date is stored.
     */
    public static function is_fine_schedule_registered( $client_post_id ) {
        $date = get_post_meta( intval( $client_post_id ), 'fine_schedule_county_registered_date', true );
        return ! empty( $date );
    }

    /**
     * Record the date a client's Fine Schedule was registered with the
     * county. Pass an empty string to clear/unset registration status.
     */
    public static function set_fine_schedule_registration_date( $client_post_id, $date ) {
        $client_post_id = intval( $client_post_id );
        $date           = sanitize_text_field( $date );

        return (bool) update_post_meta( $client_post_id, 'fine_schedule_county_registered_date', $date );
    }
}