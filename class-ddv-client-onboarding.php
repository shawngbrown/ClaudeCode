<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Client_Onboarding
 *
 * The mini-wizard tenant staff use to onboard THEIR OWN client — distinct
 * from DDV_Onboarding (which onboards a new DDV tenant itself and creates
 * a WP user). This never creates a WP user: a tenant's clients are never
 * DDV platform users, direct or indirect (architecture doc Section 4.1).
 * It produces a Client Workspace CPT with a real PCMF code and a validated
 * entity-type classification.
 *
 * Authority: default is Prime Admin only, delegatable to PM/Admin via
 * DDV_Tenant::set_client_provisioning_delegates() — a Prime-Admin-only
 * control.
 *
 * Entity-type list: a "hyper-extended list, no free-text" design —
 * available types are whichever data/{entity}.json files actually exist.
 * For the rare case a client's real type isn't covered yet, the caller
 * (eventual UI) passes the closest available match plus
 * needs_entity_review => true, and the client proceeds immediately
 * (never blocked on a support wait) with the record flagged for async
 * correction — a natural future input to DDV_Reconciliation's sweep.
 */
class DDV_Client_Onboarding {

    /**
     * Return the currently available entity types, scanned from
     * data/*.json — whatever JSON files actually exist on disk right
     * now, keyed by entity_type => human label.
     */
    public static function get_available_entity_types() {
        $dir = DDV_CORE_PATH . 'data/';
        $types = [];

        if ( ! is_dir( $dir ) ) {
            return $types;
        }

        foreach ( glob( $dir . '*.json' ) as $file ) {
            $entity_type = basename( $file, '.json' );
            $types[ $entity_type ] = self::humanize( $entity_type );
        }

        return $types;
    }

    /**
     * Onboard a client under a tenant.
     *
     * $args expects: client_name, entity_type (must match one of
     * get_available_entity_types()'s keys), optional needs_entity_review
     * (bool — set when the UI let someone pick a closest-match type
     * rather than an exact one).
     *
     * Returns the new Client Workspace post ID, or WP_Error.
     */
    public static function onboard_client( $acting_user_id, $tenant_id, array $args ) {
        $tenant_id = intval( $tenant_id );

        if ( ! DDV_Tenant::can_provision_clients( $acting_user_id, $tenant_id ) ) {
            return new WP_Error(
                'ddv_client_onboarding_forbidden',
                'You do not have authority to onboard a client for this tenant.',
                [ 'status' => 403 ]
            );
        }

        $entity_type = sanitize_key( $args['entity_type'] ?? '' );
        $available   = self::get_available_entity_types();

        if ( ! array_key_exists( $entity_type, $available ) ) {
            return new WP_Error(
                'ddv_client_onboarding_invalid_entity_type',
                "'{$entity_type}' is not a currently available entity type.",
                [ 'available' => array_keys( $available ) ]
            );
        }

        $client_name = sanitize_text_field( $args['client_name'] ?? '' );
        if ( empty( $client_name ) ) {
            return new WP_Error( 'ddv_client_onboarding_missing_name', 'client_name is required.' );
        }

        // Belt-and-suspenders: make sure this entity's compliance taxonomy
        // terms exist before the client is created against them. Cheap
        // and idempotent — a no-op if already seeded.
        DDV_Compliance_Taxonomy::seed_from_entity( $entity_type );

        $client_post_id = DDV_Client_Workspace::create_client( $tenant_id, [
            'client_name'          => $client_name,
            'business_type'        => $entity_type,
            'needs_entity_review'  => ! empty( $args['needs_entity_review'] ),
        ] );

        if ( ! is_wp_error( $client_post_id ) ) {
            $folder_provisioned = DDV_Vault_Connector::provision_client_folder( $tenant_id, $client_post_id );

            if ( ! $folder_provisioned ) {
                // Provisioning failed (bad/missing Nextcloud credentials, connection
                // failure, etc. -- provision_client_folder() already error_log'd the
                // specific cause). Client onboarding itself still succeeded -- the
                // Client Workspace record is real and shouldn't be blocked or
                // duplicated by a storage-layer failure -- but this can no longer be
                // swallowed silently. Same flag-and-proceed pattern already used for
                // needs_entity_review above.
                //
                // NOTE: as of this writing, DDV_Reconciliation does not yet sweep for
                // needs_entity_review either -- so this flag, like that one, is
                // currently discoverable only by direct query, not auto-retried.
                // Building that sweep is a separate, still-open piece of work.
                update_post_meta( $client_post_id, 'needs_folder_provisioning', true );
                error_log( "DDV_Client_Onboarding: client {$client_post_id} (tenant {$tenant_id}) onboarded successfully but Nextcloud folder provisioning FAILED -- flagged needs_folder_provisioning for retry." );
            }
        }

        return $client_post_id;
    }

    /**
     * snake_case -> readable label, e.g. "hoa_poa" -> "Hoa Poa".
     * Deliberately simple — a real entity-label lookup table (proper
     * names like "HOA / POA") is a UI-layer concern for whenever the
     * actual onboarding form gets built, not this component's job.
     */
    protected static function humanize( $key ) {
        return ucwords( str_replace( '_', ' ', $key ) );
    }
}