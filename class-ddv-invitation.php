<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Invitation
 *
 * The reusable provisioning-by-invitation engine: a Prime Admin or Admin
 * names someone for the Admin/PM/Worker tier, the system emails them a
 * single-use link, and claiming it creates their account, assigns them to
 * the correct tenant and role. Callable both from a post-onboarding "invite
 * your team" step and from an ongoing "Manage Team" screen later (the
 * augmentation-provisioning case) — nothing here is onboarding-specific.
 *
 * Window: 3 business days. Reminder emails at day 1 and day 2; the
 * invitation auto-expires (status -> 'expired') at the day-3 mark. A
 * pending invitation can also be revoked manually before that.
 */
class DDV_Invitation {

    const TABLE        = 'ddv_invitations';
    const CRON_HOOK     = 'ddv_invitation_daily_maintenance';
    const BUSINESS_DAYS = 3;

    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_daily_maintenance' ] );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Create the invitations table on plugin activation.
     */
    public static function install() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NOT NULL,
            role_slug VARCHAR(64) NOT NULL,
            email VARCHAR(191) NOT NULL,
            token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            invited_by BIGINT UNSIGNED NOT NULL,
            reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_reminder_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            claimed_at DATETIME DEFAULT NULL,
            claimed_user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY tenant_id (tenant_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Deactivation cleanup — clear the scheduled cron event.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Issue a new invitation. Returns the invitation ID, or WP_Error.
     */
    public static function create_invitation( $tenant_id, $role_slug, $email, $invited_by_user_id ) {
        global $wpdb;

        $tenant_id = intval( $tenant_id );
        $email     = sanitize_email( $email );
        $role_slug = sanitize_key( $role_slug );

        if ( empty( $tenant_id ) || empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'ddv_invitation_invalid', 'A valid tenant_id and email are required.' );
        }

        // Prime Admin is never invite-able — capped at whoever completed
        // onboarding for that tenant.
        $allowed_roles = [ DDV_Tenant::ROLE_ADMIN, DDV_Tenant::ROLE_PROGRAM_MANAGER, DDV_Tenant::ROLE_WORKER ];
        if ( ! in_array( $role_slug, $allowed_roles, true ) ) {
            return new WP_Error( 'ddv_invitation_invalid_role', 'Invitations may only be issued for Admin, Program Manager, or Worker.' );
        }

        // The person issuing the invitation must actually belong to the
        // tenant they're inviting into.
        if ( ! DDV_Tenant::user_belongs_to_tenant( $invited_by_user_id, $tenant_id ) ) {
            return new WP_Error( 'ddv_invitation_forbidden', 'You may only invite people into your own tenant.' );
        }

        $token      = self::generate_token();
        $expires_at = self::business_days_from_now( self::BUSINESS_DAYS );
        $now        = current_time( 'mysql' );
        $table      = $wpdb->prefix . self::TABLE;

        $inserted = $wpdb->insert(
            $table,
            [
                'tenant_id'  => $tenant_id,
                'role_slug'  => $role_slug,
                'email'      => $email,
                'token'      => $token,
                'status'     => 'pending',
                'invited_by' => intval( $invited_by_user_id ),
                'expires_at' => $expires_at,
                'created_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return new WP_Error( 'ddv_invitation_insert_failed', 'Could not create invitation: ' . $wpdb->last_error );
        }

        $invitation_id = (int) $wpdb->insert_id;

        self::send_invitation_email( $invitation_id );

        return $invitation_id;
    }

    /**
     * Look up a pending, unexpired invitation by its token. Returns null
     * if not found, already claimed/revoked, or past expiration (even if
     * the cron hasn't formally marked it 'expired' yet — expiry is always
     * checked live against expires_at, not just against status).
     */
    public static function get_valid_invitation_by_token( $token ) {
        global $wpdb;

        $token = sanitize_text_field( $token );
        if ( empty( $token ) ) {
            return null;
        }

        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s AND status = 'pending'", $token ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        if ( strtotime( $row['expires_at'] ) < current_time( 'timestamp' ) ) {
            self::mark_expired( $row['id'] );
            return null;
        }

        return $row;
    }

    /**
     * Claim an invitation: create (or reuse) the WP account, assign the
     * tenant + role, store the profile fields collected on the claim page,
     * mark the invitation claimed.
     *
     * $profile expects: first_name, last_name, phone, title, and either a
     * password (new account) or nothing (existing account is just added
     * to this tenant/role — a person who works for two client businesses).
     */
    public static function claim_invitation( $token, array $profile ) {
        $invitation = self::get_valid_invitation_by_token( $token );

        if ( ! $invitation ) {
            return new WP_Error( 'ddv_invitation_invalid_or_expired', 'This invitation link is invalid or has expired.' );
        }

        $email   = $invitation['email'];
        $user_id = email_exists( $email );

        if ( ! $user_id ) {
            $password = ! empty( $profile['password'] ) ? $profile['password'] : wp_generate_password( 20, true );
            $user_id  = wp_create_user( $email, $password, $email );

            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }

            wp_update_user(
                [
                    'ID'           => $user_id,
                    'first_name'   => sanitize_text_field( $profile['first_name'] ?? '' ),
                    'last_name'    => sanitize_text_field( $profile['last_name'] ?? '' ),
                    'display_name' => trim( sanitize_text_field( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) ) ),
                ]
            );
        }

        if ( ! empty( $profile['phone'] ) ) {
            update_user_meta( $user_id, 'ddv_phone', sanitize_text_field( $profile['phone'] ) );
        }
        if ( ! empty( $profile['title'] ) ) {
            update_user_meta( $user_id, 'ddv_title', sanitize_text_field( $profile['title'] ) );
        }

        DDV_Tenant::assign_user_to_tenant( $user_id, $invitation['tenant_id'] );

        $user = new WP_User( $user_id );
        $user->set_role( $invitation['role_slug'] );

        self::mark_claimed( $invitation['id'], $user_id );

        return $user_id;
    }

    /**
     * Manually revoke a still-pending invitation (e.g. wrong person named).
     * Caller is responsible for checking the requester actually belongs to
     * the tenant first.
     */
    public static function revoke_invitation( $invitation_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        return (bool) $wpdb->update(
            $table,
            [ 'status' => 'revoked' ],
            [ 'id' => intval( $invitation_id ), 'status' => 'pending' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );
    }

    /**
     * Daily cron job: send day-1 / day-2 reminders, expire anything past
     * its 3-business-day window.
     */
    public static function run_daily_maintenance() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql' );

        $pending = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'pending'",
            ARRAY_A
        );

        foreach ( $pending as $invitation ) {
            if ( strtotime( $invitation['expires_at'] ) <= current_time( 'timestamp' ) ) {
                self::mark_expired( $invitation['id'] );
                continue;
            }

            $reminders_sent   = (int) $invitation['reminder_count'];
            $last_sent        = $invitation['last_reminder_at'];
            $due_for_reminder = empty( $last_sent )
                ? true
                : ( strtotime( $last_sent ) <= strtotime( '-1 day', current_time( 'timestamp' ) ) );

            // Two reminders max (day 1, day 2) — the expiration itself on
            // day 3 is the third and final touch, sent separately below.
            if ( $reminders_sent < 2 && $due_for_reminder ) {
                self::send_reminder_email( $invitation['id'] );
                $wpdb->update(
                    $table,
                    [
                        'reminder_count'   => $reminders_sent + 1,
                        'last_reminder_at' => $now,
                    ],
                    [ 'id' => $invitation['id'] ],
                    [ '%d', '%s' ],
                    [ '%d' ]
                );
            }
        }
    }

    protected static function mark_claimed( $invitation_id, $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->update(
            $table,
            [
                'status'          => 'claimed',
                'claimed_at'      => current_time( 'mysql' ),
                'claimed_user_id' => intval( $user_id ),
            ],
            [ 'id' => intval( $invitation_id ) ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );
    }

    protected static function mark_expired( $invitation_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->update(
            $table,
            [ 'status' => 'expired' ],
            [ 'id' => intval( $invitation_id ), 'status' => 'pending' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );

        self::send_expired_notice( $invitation_id );
    }

    /**
     * Unguessable token — random bytes, not sequential/predictable.
     */
    protected static function generate_token() {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * $days business days from now, skipping Saturday/Sunday.
     */
    protected static function business_days_from_now( $days ) {
        $timestamp = current_time( 'timestamp' );
        $added     = 0;

        while ( $added < $days ) {
            $timestamp   = strtotime( '+1 day', $timestamp );
            $day_of_week = (int) date( 'N', $timestamp ); // 6 = Sat, 7 = Sun
            if ( $day_of_week < 6 ) {
                $added++;
            }
        }

        return date( 'Y-m-d H:i:s', $timestamp );
    }

    protected static function claim_url( $token ) {
        return add_query_arg( 'ddv_invite', $token, home_url( '/onboarding' ) );
    }

    protected static function send_invitation_email( $invitation_id ) {
        $invitation = self::get_invitation_row( $invitation_id );
        if ( ! $invitation ) {
            return;
        }

        $tenant        = DDV_Tenant::get_tenant( $invitation['tenant_id'] );
        $business_name = $tenant['business_name'] ?? 'your organization';

        wp_mail(
            $invitation['email'],
            sprintf( "You've been invited to join %s on DocDocVault", $business_name ),
            self::render_email_body( $invitation, "You've been invited to set up access." )
        );
    }

    protected static function send_reminder_email( $invitation_id ) {
        $invitation = self::get_invitation_row( $invitation_id );
        if ( ! $invitation ) {
            return;
        }

        wp_mail(
            $invitation['email'],
            'Reminder: your DocDocVault invitation is waiting',
            self::render_email_body( $invitation, 'This is a reminder — your invitation link is still waiting to be claimed.' )
        );
    }

    protected static function send_expired_notice( $invitation_id ) {
        $invitation = self::get_invitation_row( $invitation_id );
        if ( ! $invitation ) {
            return;
        }

        wp_mail(
            $invitation['email'],
            'Your DocDocVault invitation has expired',
            'Your invitation link has expired without being claimed. Please contact the person who invited you for a new one.'
        );
    }

    protected static function render_email_body( $invitation, $lead_line ) {
        $url = self::claim_url( $invitation['token'] );
        return $lead_line . "\n\n" . "Follow this link to verify your access and finish setting up your account:\n" . $url . "\n\n" . 'This link expires in ' . self::BUSINESS_DAYS . ' business days.';
    }

    protected static function get_invitation_row( $invitation_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", intval( $invitation_id ) ),
            ARRAY_A
        );
    }
}
