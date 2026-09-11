<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Tenant_Manager {

    /**
     * Initialize module.
     */
    public static function init() {
        // Multisite hooks (only if multisite is enabled)
        if ( is_multisite() ) {
            add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        }
    }

    /**
     * Register REST routes.
     */
    public static function register_rest_routes() {
        register_rest_route(
            'ddv/v1',
            '/tenant/create',
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'create_tenant_site' ],
                'permission_callback' => [ __CLASS__, 'can_create_tenant_site' ],
            ]
        );
    }

    /**
     * Admin settings for tenant provisioning defaults.
     */
    public static function register_settings() {
        register_setting( 'ddv_tenant_settings', 'ddv_tenant_default_role' );
        register_setting( 'ddv_tenant_settings', 'ddv_tenant_site_prefix' );
    }

    /**
     * Permission check for /tenant/create.
     *
     * A tenant site may only be created for the user making the request
     * (matched by user_id in the request body), or by someone who can
     * already manage the network. This closes the hole where any
     * unauthenticated caller could pass an arbitrary user_id and be
     * granted the default role (administrator) on a brand-new site.
     */
    public static function can_create_tenant_site( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'ddv_rest_forbidden',
                'You must be logged in to create a tenant site.',
                [ 'status' => 401 ]
            );
        }

        $requested_user_id = intval( $request->get_param( 'user_id' ) );
        $current_user_id   = get_current_user_id();

        if ( $requested_user_id === $current_user_id ) {
            return true;
        }

        // Allow network admins to create a tenant on behalf of someone else.
        if ( current_user_can( 'manage_network' ) || current_user_can( 'administrator' ) ) {
            return true;
        }

        return new WP_Error(
            'ddv_rest_forbidden',
            'You may only create a tenant site for your own account.',
            [ 'status' => 403 ]
        );
    }

    /**
     * Create a tenant site in WordPress multisite.
     *
     * Expects:
     * - user_id
     * - business_name
     * - industry
     * - state
     * - entity_type
     */
    public static function create_tenant_site( WP_REST_Request $request ) {
        if ( ! is_multisite() ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Multisite is required for tenant provisioning.',
                ],
                400
            );
        }

        $user_id       = intval( $request->get_param( 'user_id' ) );
        $business_name = sanitize_text_field( $request->get_param( 'business_name' ) );
        $industry      = sanitize_text_field( $request->get_param( 'industry' ) );
        $state         = sanitize_text_field( $request->get_param( 'state' ) );
        $entity_type   = sanitize_key( $request->get_param( 'entity_type' ) );

        if ( empty( $user_id ) || empty( $business_name ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'user_id and business_name are required.',
                ],
                400
            );
        }

        // Generate site slug
        $slug = self::generate_site_slug( $business_name, $entity_type );

        // Create site
        $site_id = wpmu_create_blog(
            network_home_url(),
            $slug,
            $business_name,
            $user_id,
            [],
            get_current_network_id()
        );

        if ( is_wp_error( $site_id ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => $site_id->get_error_message(),
                ],
                400
            );
        }

        // Assign default role
        $default_role = get_option( 'ddv_tenant_default_role', 'administrator' );
        add_user_to_blog( $site_id, $user_id, $default_role );

        // Store tenant metadata
        update_blog_option( $site_id, 'ddv_tenant_profile', [
            'business_name' => $business_name,
            'industry'      => $industry,
            'state'         => $state,
            'entity_type'   => $entity_type,
        ]);

        return new WP_REST_Response(
            [
                'status'        => 'ok',
                'site_id'       => $site_id,
                'site_url'      => get_site_url( $site_id ),
                'site_slug'     => $slug,
                'business_name' => $business_name,
                'entity_type'   => $entity_type,
            ],
            200
        );
    }

    /**
     * Generate a tenant site slug based on business name and entity type.
     * hoa_poa is a first-class entity_type value — no separate is_poa flag
     * to keep in sync with it.
     */
    protected static function generate_site_slug( $business_name, $entity_type ) {
        $prefix = get_option( 'ddv_tenant_site_prefix', 'tenant' );

        $clean_name = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $business_name ) );
        $clean_name = trim( $clean_name, '-' );

        $entity_type = strtolower( $entity_type );

        switch ( $entity_type ) {
            case 'hoa_poa':
                return "{$prefix}-poa-{$clean_name}";
            case 'llc':
                return "{$prefix}-llc-{$clean_name}";
            case 'corporation':
                return "{$prefix}-corp-{$clean_name}";
            case 'nonprofit':
                return "{$prefix}-np-{$clean_name}";
            case 'lp':
                return "{$prefix}-lp-{$clean_name}";
            case 'llp':
                return "{$prefix}-llp-{$clean_name}";
            case 'partnership':
                return "{$prefix}-partnership-{$clean_name}";
            case 'sole_proprietorship':
            default:
                return "{$prefix}-bus-{$clean_name}";
        }
    }
}
