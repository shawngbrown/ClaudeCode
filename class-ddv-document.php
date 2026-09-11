<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Document
 *
 * One post = one pointer to a real file living in Nextcloud. This is
 * the WP-as-gateway piece from Addendum 6: rather than sending users
 * to cloud.docdocvault.com's own web UI (which would require real SSO
 * — see Addendum 5's confirmed gap), documents are represented and
 * browsed entirely inside WordPress, with the actual file content
 * staying in Nextcloud the whole time. This post never stores file
 * content itself — only the pointer (nc_path) and metadata needed to
 * browse/filter it.
 *
 * Two independent, deliberately separate filter axes (Addendum 6):
 * - category/subcategory: FIXED, matches the real compliance folder
 *   structure, drives the actual WebDAV path. Answers "what kind of
 *   document is this."
 * - ddv_document_tag (taxonomy): FREE-FORM, user-assignable. Answers
 *   "how do I want to slice across everything right now" — the real
 *   SharePoint-style flexible filtering a rigid folder tree alone
 *   can't provide.
 *
 * Role-scoped visibility (Addendum 6) reuses the exact pre_get_posts
 * pattern already proven in DDV_Client_Workspace and
 * DDV_Enforcement_Case — no new access-control mechanism invented.
 * Prime Admin bypasses entirely; other roles only see documents
 * either unrestricted or explicitly listing their role in
 * visible_to_roles.
 *
 * Sync/reconciliation (keeping nc_last_modified accurate against
 * files edited outside WordPress — Collabora, direct NC access) is
 * DDV_Reconciliation's job, not this class's — see Addendum 6, still
 * unbuilt as of this writing.
 */
class DDV_Document {

    const POST_TYPE = 'ddv_document';
    const TAX_TAG    = 'ddv_document_tag';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'scope_to_tenant_and_role' ] );
    }

    public static function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Documents',
                'singular_name' => 'Document',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'capability_type'     => [ 'ddv_document', 'ddv_documents' ],
            'map_meta_cap'        => true,
            'supports'            => [ 'title', 'custom-fields' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
        ] );

        register_post_meta( self::POST_TYPE, 'tenant_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
        ] );

        register_post_meta( self::POST_TYPE, 'client_post_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
        ] );

        // Nullable — most documents aren't tied to an enforcement case.
        register_post_meta( self::POST_TYPE, 'case_post_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
        ] );

        register_post_meta( self::POST_TYPE, 'category', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'subcategory', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // The pointer itself — WebDAV-relative path to the real file
        // in Nextcloud. This post never stores file content.
        register_post_meta( self::POST_TYPE, 'nc_path', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // What DDV_Reconciliation compares against a live WebDAV check
        // to detect drift from edits made outside WordPress.
        register_post_meta( self::POST_TYPE, 'nc_last_modified', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Nullable — ties into the JIT generation work (Addendum 3/4)
        // later; not every document has a structured type.
        register_post_meta( self::POST_TYPE, 'document_type', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // CSV of role slugs, reusing the exact same storage convention
        // already established in DDV_Tenant::client_provisioning_delegates
        // — no new pattern invented. Empty = visible to the whole
        // tenant (the common case); non-empty = restricted to the
        // listed roles only.
        register_post_meta( self::POST_TYPE, 'visible_to_roles', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Written by DDV_Reconciliation, not by create_document() —
        // a brand-new pointer starts 'pending' (never checked yet).
        // 'ok' = matched on last check; 'drifted' = file was touched
        // outside WordPress since last known (Collabora, direct NC
        // access); 'missing' = the file is genuinely gone. Flagged
        // for human review on 'drifted'/'missing', per this project's
        // standing never-autonomous-action principle — never silently
        // auto-corrected and hidden.
        register_post_meta( self::POST_TYPE, 'reconciliation_status', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // When DDV_Reconciliation last actually checked this document
        // against the real Nextcloud file — drives run_batch()'s
        // oldest-checked-first ordering.
        register_post_meta( self::POST_TYPE, 'last_reconciled_at', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    public static function register_taxonomy() {
        register_taxonomy( self::TAX_TAG, self::POST_TYPE, [
            'labels' => [
                'name'          => 'Document Tags',
                'singular_name' => 'Document Tag',
            ],
            'hierarchical'  => false, // flat, unbounded — free-form by design
            'public'        => false,
            'show_ui'       => true,
            'show_in_rest'  => true,
        ] );
    }

    /**
     * Create a new document pointer. This does NOT touch Nextcloud at
     * all — it only records that a file exists at $nc_path and what
     * it's about. Actually placing the file (WebDAV PUT) is a separate
     * step, driven by whatever created the document (a JIT-generated
     * letter, an uploaded scan, etc.) — kept separate so this class
     * stays a pure pointer/index, not a file-transfer mechanism.
     *
     * $args expects: client_post_id, category, nc_path, and
     * optionally: case_post_id, subcategory, document_type,
     * visible_to_roles (array of role slugs — converted to CSV
     * internally), tags (array of free-form tag strings).
     */
    public static function create_document( $tenant_id, array $args ) {
        $tenant_id      = intval( $tenant_id );
        $client_post_id = intval( $args['client_post_id'] ?? 0 );
        $category       = sanitize_key( $args['category'] ?? '' );
        $nc_path        = sanitize_text_field( $args['nc_path'] ?? '' );
        $title          = sanitize_text_field( $args['title'] ?? basename( $nc_path ) );

        if ( ! $tenant_id || ! $client_post_id || ! $category || ! $nc_path ) {
            return new WP_Error( 'ddv_document_missing_fields', 'tenant_id, client_post_id, category, and nc_path are required.' );
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_title'  => $title,
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'tenant_id', $tenant_id );
        update_post_meta( $post_id, 'client_post_id', $client_post_id );
        update_post_meta( $post_id, 'category', $category );
        update_post_meta( $post_id, 'nc_path', $nc_path );
        update_post_meta( $post_id, 'nc_last_modified', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'reconciliation_status', 'pending' );

        // Deliberately an old sentinel, not empty. DDV_Reconciliation's
        // run_batch() filters by meta_key => 'last_reconciled_at' —
        // WP_Query's meta_key filtering REQUIRES the key to exist at
        // all, so a document with this field unset would be silently
        // excluded from every future reconciliation run, never
        // checked. Setting a deliberately old value here guarantees
        // every document both has the key (included in the query) and
        // sorts first (checked with priority, as a never-checked
        // document should be).
        update_post_meta( $post_id, 'last_reconciled_at', '1970-01-01 00:00:00' );

        if ( ! empty( $args['case_post_id'] ) ) {
            update_post_meta( $post_id, 'case_post_id', intval( $args['case_post_id'] ) );
        }

        if ( ! empty( $args['subcategory'] ) ) {
            update_post_meta( $post_id, 'subcategory', sanitize_key( $args['subcategory'] ) );
        }

        if ( ! empty( $args['document_type'] ) ) {
            update_post_meta( $post_id, 'document_type', sanitize_key( $args['document_type'] ) );
        }

        if ( ! empty( $args['visible_to_roles'] ) && is_array( $args['visible_to_roles'] ) ) {
            $roles = array_map( 'sanitize_key', $args['visible_to_roles'] );
            update_post_meta( $post_id, 'visible_to_roles', implode( ',', $roles ) );
        }

        if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
            wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $args['tags'] ), self::TAX_TAG );
        }

        return $post_id;
    }

    /**
     * Role-scoped tenant isolation. Same pre_get_posts pattern as
     * DDV_Client_Workspace/DDV_Enforcement_Case, extended with a
     * second axis: Prime Admin (ddv_lead_admin) bypasses entirely and
     * sees everything within their own tenant (still tenant-scoped —
     * NOT a cross-tenant bypass, only manage_options gets that);
     * everyone else only sees documents that are either unrestricted
     * (empty visible_to_roles) or explicitly list one of their roles.
     */
    public static function scope_to_tenant_and_role( $query ) {
        if ( $query->get( 'post_type' ) !== self::POST_TYPE ) {
            return;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        $user_id = get_current_user_id();
        $tenant  = DDV_Tenant::get_tenant_by_user( $user_id );

        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = [];
        }

        if ( ! $tenant ) {
            $meta_query[] = [
                'key'   => 'tenant_id',
                'value' => 0,
            ];
            $query->set( 'meta_query', $meta_query );
            return;
        }

        $meta_query[] = [
            'key'   => 'tenant_id',
            'value' => (int) $tenant['id'],
        ];

        $user  = get_userdata( $user_id );
        $roles = $user ? (array) $user->roles : [];

        // Prime Admin sees everything within their own tenant — the
        // tenant_id clause above already applied; no further
        // role-based restriction needed.
        if ( in_array( DDV_Tenant::ROLE_PRIME_ADMIN, $roles, true ) ) {
            $query->set( 'meta_query', $meta_query );
            return;
        }

        // Everyone else: visible if visible_to_roles is empty/unset
        // (unrestricted — the common case) OR if it explicitly
        // contains one of their roles.
        $role_clauses = [ 'relation' => 'OR' ];

        $role_clauses[] = [
            'key'     => 'visible_to_roles',
            'value'   => '',
            'compare' => '=',
        ];

        $role_clauses[] = [
            'key'     => 'visible_to_roles',
            'compare' => 'NOT EXISTS',
        ];

        foreach ( $roles as $role ) {
            $role_clauses[] = [
                'key'     => 'visible_to_roles',
                'value'   => $role,
                'compare' => 'LIKE',
            ];
        }

        $meta_query[] = $role_clauses;

        $query->set( 'meta_query', $meta_query );
    }
}