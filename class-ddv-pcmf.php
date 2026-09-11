<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_PCMF
 *
 * Generates and parses the PCMF (Primary Client Meta Folder) numbering
 * codes defined in the architecture reference, Section 3.2:
 *
 *   Tenant:   PCMF-M####-S01
 *   Client:   PCMF-M####-S01-C####
 *   Document: PCMF-M####-S01-C####-D####
 *   Version:  PCMF-M####-S01-C####-D####-V##
 *
 * Deliberately pure logic — given IDs in, produces a code string out (or
 * the reverse). No database queries, no Nextcloud calls, no side effects.
 * This is the first build-order step specifically because it can be
 * fully verified in isolation before anything depends on it.
 *
 * M (meta) reuses wp_ddv_tenants.id directly rather than inventing a
 * second counter — same principle already applied to Client (the Client
 * Workspace CPT's own post ID) and Document (that CPT's own post ID).
 * S is always "01" — there is exactly one master folder per tenant
 * (confirmed: no S02 exists; see architecture doc Section 3.2).
 *
 * This code is never shown to users (confirmed not-human-visible in the
 * architecture doc) — it lives purely as post/term metadata.
 */
class DDV_PCMF {

    const PREFIX = 'PCMF';

    // Widths chosen generously so no tier realistically hits its ceiling.
    const WIDTH_META     = 4; // supports 9,999 tenants
    const WIDTH_CLIENT   = 4; // supports 9,999 clients per tenant
    const WIDTH_DOCUMENT = 4; // supports 9,999 documents per client
    const WIDTH_VERSION  = 2; // supports 99 versions per document

    /**
     * PCMF-M####-S01
     */
    public static function tenant_code( $tenant_id ) {
        $meta = self::validate_id( $tenant_id, 'tenant_id' );
        if ( is_wp_error( $meta ) ) {
            return $meta;
        }

        return self::PREFIX . '-M' . self::pad( $meta, self::WIDTH_META ) . '-S01';
    }

    /**
     * PCMF-M####-S01-C####
     */
    public static function client_code( $tenant_id, $client_id ) {
        $tenant_code = self::tenant_code( $tenant_id );
        if ( is_wp_error( $tenant_code ) ) {
            return $tenant_code;
        }

        $client = self::validate_id( $client_id, 'client_id' );
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        return $tenant_code . '-C' . self::pad( $client, self::WIDTH_CLIENT );
    }

    /**
     * PCMF-M####-S01-C####-D####
     */
    public static function document_code( $tenant_id, $client_id, $document_id ) {
        $client_code = self::client_code( $tenant_id, $client_id );
        if ( is_wp_error( $client_code ) ) {
            return $client_code;
        }

        $document = self::validate_id( $document_id, 'document_id' );
        if ( is_wp_error( $document ) ) {
            return $document;
        }

        return $client_code . '-D' . self::pad( $document, self::WIDTH_DOCUMENT );
    }

    /**
     * PCMF-M####-S01-C####-D####-V##
     */
    public static function version_code( $tenant_id, $client_id, $document_id, $version ) {
        $document_code = self::document_code( $tenant_id, $client_id, $document_id );
        if ( is_wp_error( $document_code ) ) {
            return $document_code;
        }

        $version_num = self::validate_id( $version, 'version' );
        if ( is_wp_error( $version_num ) ) {
            return $version_num;
        }

        return $document_code . '-V' . self::pad( $version_num, self::WIDTH_VERSION );
    }

    /**
     * Parse any PCMF code back into its component IDs. Accepts a partial
     * code (tenant-only, tenant+client, etc.) — returns whichever segments
     * are present, null for the rest.
     *
     * Returns an array: ['tenant_id' => int, 'client_id' => int|null,
     * 'document_id' => int|null, 'version' => int|null] or WP_Error if the
     * string doesn't match the expected PCMF pattern at all.
     */
    public static function parse( $code ) {
        $pattern = '/^PCMF-M(\d{' . self::WIDTH_META . '})-S01(?:-C(\d{' . self::WIDTH_CLIENT . '}))?(?:-D(\d{' . self::WIDTH_DOCUMENT . '}))?(?:-V(\d{' . self::WIDTH_VERSION . '}))?$/';

        if ( ! preg_match( $pattern, trim( (string) $code ), $matches ) ) {
            return new WP_Error( 'ddv_pcmf_invalid_code', 'This does not match the expected PCMF code format.', [ 'code' => $code ] );
        }

        return [
            'tenant_id'   => (int) $matches[1],
            'client_id'   => isset( $matches[2] ) && $matches[2] !== '' ? (int) $matches[2] : null,
            'document_id' => isset( $matches[3] ) && $matches[3] !== '' ? (int) $matches[3] : null,
            'version'     => isset( $matches[4] ) && $matches[4] !== '' ? (int) $matches[4] : null,
        ];
    }

    /**
     * A positive integer, zero-padded to $width digits. WP_Error if the
     * value is out of range for that width, so a silently-truncated or
     * malformed code can never be generated.
     */
    protected static function pad( $number, $width ) {
        return str_pad( (string) $number, $width, '0', STR_PAD_LEFT );
    }

    /**
     * A segment ID must be a positive integer that fits within its
     * segment's configured width. Returns the validated int, or WP_Error.
     */
    protected static function validate_id( $value, $field_name ) {
        if ( ! is_numeric( $value ) || intval( $value ) != $value || intval( $value ) < 1 ) {
            return new WP_Error( 'ddv_pcmf_invalid_id', "{$field_name} must be a positive integer.", [ 'value' => $value ] );
        }

        $width_map = [
            'tenant_id'   => self::WIDTH_META,
            'client_id'   => self::WIDTH_CLIENT,
            'document_id' => self::WIDTH_DOCUMENT,
            'version'     => self::WIDTH_VERSION,
        ];

        $max = pow( 10, $width_map[ $field_name ] ) - 1;

        if ( intval( $value ) > $max ) {
            return new WP_Error( 'ddv_pcmf_id_too_large', "{$field_name} ({$value}) exceeds the maximum supported by its segment width ({$max}).", [ 'value' => $value ] );
        }

        return intval( $value );
    }

    /**
     * Lightweight smoke test — no PHPUnit required. Run on the live
     * server via WP-CLI:
     *   wp eval 'var_dump(DDV_PCMF::self_test());'
     * Returns true if every check passes, or a string describing the
     * first failure.
     */
    public static function self_test() {
        $tenant = self::tenant_code( 1 );
        if ( $tenant !== 'PCMF-M0001-S01' ) {
            return "FAIL: tenant_code(1) returned '{$tenant}', expected 'PCMF-M0001-S01'";
        }

        $client = self::client_code( 1, 42 );
        if ( $client !== 'PCMF-M0001-S01-C0042' ) {
            return "FAIL: client_code(1, 42) returned '{$client}', expected 'PCMF-M0001-S01-C0042'";
        }

        $document = self::document_code( 1, 42, 7 );
        if ( $document !== 'PCMF-M0001-S01-C0042-D0007' ) {
            return "FAIL: document_code(1, 42, 7) returned '{$document}', expected 'PCMF-M0001-S01-C0042-D0007'";
        }

        $version = self::version_code( 1, 42, 7, 3 );
        if ( $version !== 'PCMF-M0001-S01-C0042-D0007-V03' ) {
            return "FAIL: version_code(1, 42, 7, 3) returned '{$version}', expected 'PCMF-M0001-S01-C0042-D0007-V03'";
        }

        // Round-trip: parse what was just generated, confirm it matches
        // the original inputs exactly.
        $parsed = self::parse( $version );
        if ( is_wp_error( $parsed ) ) {
            return 'FAIL: parse() could not parse a code this class itself generated: ' . $parsed->get_error_message();
        }
        if ( $parsed['tenant_id'] !== 1 || $parsed['client_id'] !== 42 || $parsed['document_id'] !== 7 || $parsed['version'] !== 3 ) {
            return 'FAIL: round-trip parse did not match original inputs: ' . wp_json_encode( $parsed );
        }

        // Partial code (tenant + client only) should parse with the
        // remaining segments as null, not error.
        $partial = self::parse( 'PCMF-M0001-S01-C0042' );
        if ( is_wp_error( $partial ) || $partial['document_id'] !== null || $partial['version'] !== null ) {
            return 'FAIL: partial code did not parse correctly: ' . wp_json_encode( $partial );
        }

        // Invalid input should error, not silently produce a malformed code.
        $invalid = self::tenant_code( 'not-a-number' );
        if ( ! is_wp_error( $invalid ) ) {
            return 'FAIL: tenant_code() accepted a non-numeric value without error.';
        }

        // An ID exceeding its segment width should error, not truncate.
        $too_large = self::tenant_code( 100000 ); // exceeds WIDTH_META's 9,999 max
        if ( ! is_wp_error( $too_large ) ) {
            return 'FAIL: tenant_code() accepted an out-of-range value without error.';
        }

        // Garbage input to parse() should error cleanly, not warn/crash.
        $garbage = self::parse( 'not-a-pcmf-code-at-all' );
        if ( ! is_wp_error( $garbage ) ) {
            return 'FAIL: parse() accepted a non-PCMF string without error.';
        }

        return true;
    }
}
