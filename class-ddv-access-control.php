<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Access_Control
 *
 * Enforces "no wp-admin access, front-end only" for the four tenant-tier
 * roles (Prime Admin, Admin, Program Manager, Worker). These roles were
 * cloned from Administrator via User Role Editor and may carry more
 * wp-admin capability than intended — this class doesn't try to strip
 * capabilities one by one; it blocks the wp-admin area outright for anyone
 * holding a DDV tier role who isn't also a real site Administrator, and
 * routes them to their front-end Dashboard page instead.
 */
class DDV_Access_Control {

    /**
     * Slug of the front-end page tenant users land on instead of wp-admin.
     * Matches the confirmed nav slug from onboarding.
     */
    const DASHBOARD_SLUG = 'dashboard';

    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'block_wp_admin_for_tenant_roles' ] );
        add_filter( 'login_redirect', [ __CLASS__, 'redirect_after_login' ], 10, 3 );
        add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar_for_tenant_roles' ] );
        add_filter( 'wp_is_application_passwords_available_for_user', [ __CLASS__, 'disable_app_passwords_for_tenant_roles' ], 10, 2 );
    }

    /**
     * True if this user holds one of the four DDV tier roles and does NOT
     * also hold a real site-management capability. A user with both (e.g.
     * you, testing) is left alone — this only restricts people who are
     * *only* a tenant-tier user.
     */
    protected static function is_tenant_only_user( $user ) {
        if ( ! $user instanceof WP_User ) {
            $user = wp_get_current_user();
        }

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $tier_roles = DDV_Tenant::get_tier_roles();
        $has_tier_role = (bool) array_intersect( $tier_roles, (array) $user->roles );

        if ( ! $has_tier_role ) {
            return false;
        }

        // A real WP administrator (manage_options) is never blocked, even
        // if they also happen to hold a DDV tier role for testing.
        return ! $user->has_cap( 'manage_options' );
    }

    /**
     * Redirect tenant-only users away from wp-admin entirely, except for
     * the one wp-admin request every login-flow depends on: admin-ajax.php,
     * and the small set of actions a logged-in front-end user still needs
     * (like the login/logout post handler). Everything else in wp-admin
     * bounces to the front-end Dashboard page.
     */
    public static function block_wp_admin_for_tenant_roles() {
        if ( wp_doing_ajax() ) {
            return;
        }

        if ( ! self::is_tenant_only_user( wp_get_current_user() ) ) {
            return;
        }

        wp_safe_redirect( self::dashboard_url() );
        exit;
    }

    /**
     * After logging in, send tenant-only users straight to the front-end
     * Dashboard instead of wp-admin (WordPress's normal default).
     */
    public static function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user instanceof WP_User && self::is_tenant_only_user( $user ) ) {
            return self::dashboard_url();
        }

        return $redirect_to;
    }

    /**
     * No wp-admin toolbar on the front end for tenant-only users — they
     * have no wp-admin destination for it to link to anyway.
     */
    public static function hide_admin_bar_for_tenant_roles( $show ) {
        if ( self::is_tenant_only_user( wp_get_current_user() ) ) {
            return false;
        }

        return $show;
    }

    /**
     * Application Passwords authenticate directly against the REST API,
     * bypassing whatever front-end flow a tenant-tier user is meant to go
     * through. Disabled for tenant-only users; unaffected for real admins.
     */
    public static function disable_app_passwords_for_tenant_roles( $available, $user ) {
        if ( $user instanceof WP_User && self::is_tenant_only_user( $user ) ) {
            return false;
        }

        return $available;
    }

    protected static function dashboard_url() {
        return home_url( '/' . self::DASHBOARD_SLUG );
    }
}
