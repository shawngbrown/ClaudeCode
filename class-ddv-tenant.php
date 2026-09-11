<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Tenant
 *
 * Tenant identity as a first-class record, independent of any one WP user.
 * This replaces the scattered ddv_tenant_profile / ddv_entity_type / ddv_is_poa
 * user-meta approach that finalize_onboarding() used to write. Every user who
 * belongs to a tenant (Prime Admin, Admin, PM, Worker) now points at one row
 * here via a 'ddv_tenant_id' user-meta value, so multiple users can share a
 * tenant — which the old one-user-equals-one-tenant meta approach could not
 * support.
 *
 * Deliberately built so a later multisite migration only has to add a
 * nullable wp_site_id column to this table — every REST permission check,
 * Nextcloud group-naming call, etc. built against tenant_id keeps working
 * unchanged; only DDV_Tenant_Manager's job (spin up a subsite or not) changes.
 */
class DDV_Tenant {

    const TABLE = 'ddv_tenants';

    // The four tier roles, as actually registered in wp-admin (confirmed
    // 2026-07-24). This is the single source of truth for role slugs —
    // DDV_Onboarding and the invitation engine both reference these
    // constants rather than hardcoding the strings themselves.
    const ROLE_PRIME_ADMIN     = 'ddv_lead_admin';
    const ROLE_ADMIN           = 'ddv_common_admin';
    const ROLE_PROGRAM_MANAGER = 'ddv_program_manager';
    const ROLE_WORKER          = 'ddv_worker';

    /**
     * All four tier roles in descending order of authority. Useful for
     * building the invitation form's tier dropdown, and for permission
     * checks that need "at least Admin" style comparisons.
     */
    public static function get_tier_roles() {
        return [
            self::ROLE_PRIME_ADMIN,
            self::ROLE_ADMIN,
            self::ROLE_PROGRAM_MANAGER,
            self::ROLE_WORKER,
        ];
    }

    /**
     * Create the tenants table on plugin activation.
     * Call via register_activation_hook(), not on every page load.
     */
    public static function install() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta is picky about formatting: two spaces after PRIMARY KEY,
        // each field on its own line, no trailing commas.
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_slug VARCHAR(191) NOT NULL,
            business_name VARCHAR(255) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            is_poa TINYINT(1) NOT NULL DEFAULT 0,
            industry VARCHAR(191) DEFAULT '',
            state VARCHAR(64) DEFAULT '',
            prime_admin_user_id BIGINT UNSIGNED NOT NULL,
            wp_site_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            client_provisioning_delegates VARCHAR(255) NOT NULL DEFAULT '',
            nc_folder_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_slug (tenant_slug),
            KEY prime_admin_user_id (prime_admin_user_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create a new tenant row and return its ID.
     *
     * Expects: business_name, entity_type, industry, state,
     * prime_admin_user_id. is_poa is derived automatically from entity_type
     * ('hoa_poa') — do not pass it in.
     */
    public static function create_tenant( array $args ) {
        global $wpdb;

        $business_name = sanitize_text_field( $args['business_name'] ?? '' );
        $entity_type   = sanitize_key( $args['entity_type'] ?? '' );
        // Derived, not accepted as separate input — hoa_poa is a first-class
        // entity_type value now, so this can never disagree with it the way
        // a hand-set checkbox could.
        $is_poa        = ( $entity_type === 'hoa_poa' ) ? 1 : 0;
        $industry      = sanitize_text_field( $args['industry'] ?? '' );
        $state         = sanitize_text_field( $args['state'] ?? '' );
        $user_id       = intval( $args['prime_admin_user_id'] ?? 0 );

        if ( empty( $business_name ) || empty( $entity_type ) || empty( $user_id ) ) {
            return new WP_Error( 'ddv_tenant_missing_fields', 'business_name, entity_type, and prime_admin_user_id are required.' );
        }

        $slug = self::generate_unique_slug( $business_name, $entity_type );
        $now  = current_time( 'mysql' );

        $table = $wpdb->prefix . self::TABLE;

        $inserted = $wpdb->insert(
            $table,
            [
                'tenant_slug'          => $slug,
                'business_name'        => $business_name,
                'entity_type'          => $entity_type,
                'is_poa'               => $is_poa,
                'industry'             => $industry,
                'state'                => $state,
                'prime_admin_user_id'  => $user_id,
                'status'               => 'active',
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return new WP_Error( 'ddv_tenant_insert_failed', 'Could not create tenant record: ' . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Fetch a tenant row by ID.
     */
    public static function get_tenant( $tenant_id ) {
        global $wpdb;

        $tenant_id = intval( $tenant_id );
        if ( empty( $tenant_id ) ) {
            return null;
        }

        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $tenant_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Fetch the tenant a given user belongs to, via their ddv_tenant_id meta.
     */
    public static function get_tenant_by_user( $user_id ) {
        $tenant_id = get_user_meta( intval( $user_id ), 'ddv_tenant_id', true );

        if ( empty( $tenant_id ) ) {
            return null;
        }

        return self::get_tenant( $tenant_id );
    }

    /**
     * Point a user at a tenant. Does not touch their role — call
     * wp_update_user()/set_role() separately with the correct tier role.
     */
    public static function assign_user_to_tenant( $user_id, $tenant_id ) {
        return (bool) update_user_meta( intval( $user_id ), 'ddv_tenant_id', intval( $tenant_id ) );
    }

    /**
     * Store the Nextcloud Group Folder ID that was created for this
     * tenant. Called once, right after DDV_Vault_Connector provisions
     * the tenant's top-level vault folder.
     */
    public static function set_nc_folder_id( $tenant_id, $folder_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $updated = $wpdb->update(
            $table,
            [ 'nc_folder_id' => intval( $folder_id ) ],
            [ 'id' => intval( $tenant_id ) ],
            [ '%d' ],
            [ '%d' ]
        );

        return false !== $updated;
    }

    /**
     * True if $user_id belongs to $tenant_id. The check every REST
     * permission callback and future Nextcloud-scoping call should use
     * alongside a role check — role alone does not tell you which tenant
     * someone belongs to on a single-site deployment.
     */
    public static function user_belongs_to_tenant( $user_id, $tenant_id ) {
        $actual = get_user_meta( intval( $user_id ), 'ddv_tenant_id', true );
        return ! empty( $actual ) && intval( $actual ) === intval( $tenant_id );
    }

    /**
     * True if $user_id may trigger client onboarding for $tenant_id.
     * Default authority is Prime Admin only. Delegatable to PM and/or
     * Admin via set_client_provisioning_delegates() — a control only
     * Prime Admin can access (enforced by the caller, same pattern as
     * every other Prime-Admin-only action in this codebase).
     */
    public static function can_provision_clients( $user_id, $tenant_id ) {
        if ( ! self::user_belongs_to_tenant( $user_id, $tenant_id ) ) {
            return false;
        }

        $user = get_userdata( intval( $user_id ) );
        if ( ! $user ) {
            return false;
        }

        if ( in_array( self::ROLE_PRIME_ADMIN, (array) $user->roles, true ) ) {
            return true;
        }

        $tenant = self::get_tenant( $tenant_id );
        if ( ! $tenant ) {
            return false;
        }

        $delegated = array_filter( explode( ',', (string) $tenant['client_provisioning_delegates'] ) );

        return (bool) array_intersect( $delegated, (array) $user->roles );
    }

    /**
     * Prime-Admin-only setter — the toggle described in the architecture
     * doc. Caller MUST verify the requester is actually that tenant's
     * Prime Admin before calling this; this method itself only performs
     * the write, not the permission check (same division of
     * responsibility used throughout this codebase's REST callbacks).
     */
    public static function set_client_provisioning_delegates( $tenant_id, array $role_slugs ) {
        global $wpdb;

        $allowed = [ self::ROLE_ADMIN, self::ROLE_PROGRAM_MANAGER ];
        $role_slugs = array_values( array_intersect( $role_slugs, $allowed ) );

        $table = $wpdb->prefix . self::TABLE;

        $updated = $wpdb->update(
            $table,
            [ 'client_provisioning_delegates' => implode( ',', $role_slugs ) ],
            [ 'id' => intval( $tenant_id ) ],
            [ '%s' ],
            [ '%d' ]
        );

        return false !== $updated;
    }

    /**
     * Same slug logic DDV_Tenant_Manager uses for site slugs, so the same
     * identifier can be reused for Nextcloud group/folder naming later and
     * (if multisite ever happens) as the eventual subsite slug too.
     */
    protected static function generate_unique_slug( $business_name, $entity_type ) {
        global $wpdb;

        $clean_name = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $business_name ) );
        $clean_name = trim( $clean_name, '-' );

        $entity_type = strtolower( $entity_type );
        $base        = "{$entity_type}-{$clean_name}";

        $table = $wpdb->prefix . self::TABLE;
        $slug  = $base;
        $i     = 1;

        // Guard against two businesses with the same cleaned name colliding
        // on the UNIQUE tenant_slug column.
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tenant_slug = %s", $slug ) ) ) {
            $i++;
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
