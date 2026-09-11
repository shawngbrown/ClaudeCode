<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Enforcement_Case
 *
 * One post = one enforcement case (e.g. a noise complaint working
 * through Complaint -> Notice -> Hearing -> [Appeal] -> Closed). Mirrors
 * DDV_Client_Workspace's pattern: a dedicated CPT, tenant_id +
 * client_post_id meta for scoping, status tracks which gate the case is
 * currently at.
 *
 * Per the design doc's folder-cascade model: each case gets its own
 * subfolder inside the client's Legal_Enforcement/ directory, with one
 * stage folder per gate. Moving the case forward is a status change on
 * this post PLUS (eventually) moving the actual document between stage
 * folders in Nextcloud — the two should stay in sync, driven from here.
 *
 * Prime Admin authority supersedes the stage-folder access rule at the
 * Nextcloud permission layer (a later phase, once role sub-groups or an
 * equivalent mechanism exists) — this class only owns the WP-side case
 * record and triggering the folder structure, not access enforcement
 * itself.
 *
 * APPEAL STAGE (added per Addendum 9/10.5, against the real text of
 * Texas Property Code §209.007): the appeal is a COMMITTEE-TO-BOARD
 * escalation, not a generic post-hearing review. §209.007(a)-(b): the
 * hearing happens before a committee appointed by the board, OR the
 * full board directly if no committee was appointed. Only if a
 * committee heard the case does the owner have the right to appeal
 * that committee's decision to the full board — if the board heard it
 * directly, there is no appeal-to-board step, since the board already
 * was the original decision-maker. The `heard_by` meta field captures
 * which body heard the case; the Appeal stage should only be offered
 * when `heard_by` is `committee`.
 *
 * Alternative Dispute Resolution (mediation/arbitration) is
 * deliberately NOT part of this workflow — per §209.007(d), it only
 * applies once the association has already filed suit (TRO,
 * injunction, or foreclosure), a materially different and much rarer
 * case type than the standard complaint-driven cascade this class
 * models.
 */
class DDV_Enforcement_Case {

    const POST_TYPE = 'ddv_enforcement_case';

    const STATUS_PENDING_REVIEW   = 'pending_review';
    const STATUS_COMPLAINT_INTAKE = 'complaint_intake';
    const STATUS_NOTICE_SENT      = 'notice_sent';
    const STATUS_HEARING_PENDING  = 'hearing_pending';
    const STATUS_APPEAL_PENDING   = 'appeal_pending';
    const STATUS_CLOSED           = 'closed';
    const STATUS_CLOSED_DISPUTED  = 'closed_disputed';

    /**
     * Every valid case_status value, including the two (Addendum 8)
     * that do NOT have their own stage folder: pending_review (a raw
     * submission that hasn't been formally opened as a case yet — no
     * Nextcloud folders exist for it) and closed_disputed (internally
     * concluded but contested externally — shares the same 05_Closed
     * folder as a clean closure, per Addendum 8's design). This is
     * deliberately a separate list from STAGE_FOLDERS below, since not
     * every valid status maps to its own folder.
     */
    const VALID_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_COMPLAINT_INTAKE,
        self::STATUS_NOTICE_SENT,
        self::STATUS_HEARING_PENDING,
        self::STATUS_APPEAL_PENDING,
        self::STATUS_CLOSED,
        self::STATUS_CLOSED_DISPUTED,
    ];

    /**
     * The five stage folders, in order. Appeal is inserted between
     * Hearing and Closed per Addendum 9 — note that not every case
     * will actually use the Appeal folder (only committee-heard cases
     * are eligible per the docblock above), but the folder is still
     * created up front along with the others, consistent with this
     * class's existing "create the full cascade at case-open time"
     * design — an empty, unused Appeal folder is harmless.
     */
    const STAGE_FOLDERS = [
        self::STATUS_COMPLAINT_INTAKE => '01_Complaint_Intake',
        self::STATUS_NOTICE_SENT      => '02_Notice_Sent',
        self::STATUS_HEARING_PENDING  => '03_Hearing_Pending',
        self::STATUS_APPEAL_PENDING   => '04_Appeal_Pending',
        self::STATUS_CLOSED           => '05_Closed',
    ];

    /**
     * Valid values for heard_by — which body actually heard the case
     * at Gate 3. Determines whether the Appeal stage is even available
     * (only 'committee' cases can be appealed to the board, per
     * §209.007(a)-(b)).
     */
    const VALID_HEARD_BY = [ 'committee', 'board' ];

    /**
     * Gate 1 field (Addendum 7): how the complainant identified the
     * alleged offender. Distinguishes "I saw it happen" from "I heard
     * something and assumed it was them" — a distinction that matters
     * enormously once a case reaches a hearing.
     */
    const VALID_BASIS_OF_IDENTIFICATION = [
        'direct_observation',
        'inference_from_location',
        'prior_knowledge',
        'other',
    ];

    /**
     * Gate 3 fact-finding fields (Addendum 7): the Hearing establishes
     * these BEFORE any outcome is decided — the outcome is a
     * conclusion drawn from these findings, not a standalone judgment
     * call made in isolation. Shared valid-value set since all three
     * findings use the same yes/no/disputed shape.
     */
    const VALID_FINDING_VALUES = [ 'yes', 'no', 'disputed' ];

    /**
     * The seven real Legal Enforcement sub-category keys, pulled from
     * the actual compliance data (confirmed 2026-08-08 via
     * DDV_Compliance_Library::get_compliance_data('hoa_poa')). A case's
     * category MUST be one of these — not a loose descriptive word
     * like the underlying complaint type (e.g. "noise", "parking").
     * The complaint's real-world nature goes in case_title/description;
     * case_category is which compliance folder the case's documents
     * belong under.
     */
    const VALID_CASE_CATEGORIES = [
        'assessment',
        'fines',
        'fees',
        'towing',
        'attorney',
        'statutory_due_process',
        'owner_rights',
    ];

    /**
     * Whether this case requires genuine investigation (disputed facts
     * — was the right party identified, did it actually happen) or is
     * a fixed, objective infraction with little to dispute (e.g. an
     * illegally parked car either is or isn't). A mediated case is
     * exactly why identity_confirmed/occurrence_confirmed/
     * correct_party_identified exist; a fixed_infraction case can
     * reasonably skip straight to an outcome without populating them.
     * The existing record_hearing() fields were already optional, so
     * no change was needed there — this just makes the classification
     * itself explicit and required at case-open time.
     */
    const VALID_CASE_NATURE = [ 'mediated', 'fixed_infraction' ];

    /**
     * Gate 2 (Formal Notice) fields — designed in this document's
     * original three-gate sketch but never actually built until now.
     */
    const VALID_NOTICE_TYPES = [ 'violation_notice', 'violation_reminder_notice' ];

    const VALID_CURE_PERIODS = [ '10_days', '15_days', '30_days', 'no_cure_available' ];

    const VALID_DELIVERY_METHODS = [ 'certified_mail', 'personal_delivery', 'other' ];

    /**
     * Gate 3 outcome — also designed originally but never built. This
     * is the conclusion drawn FROM the fact-finding fields (Addendum
     * 7), recorded as a separate action from record_hearing() itself,
     * matching how those fact-finding fields are established before
     * any decision is made.
     */
    const VALID_OUTCOMES = [ 'no_violation_found', 'cure_confirmed', 'fine_imposed', 'fine_and_continued_monitoring' ];

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
    }

    public static function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Enforcement Cases',
                'singular_name' => 'Enforcement Case',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'capability_type'     => [ 'ddv_enforcement_case', 'ddv_enforcement_cases' ],
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

        register_post_meta( self::POST_TYPE, 'case_status', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'case_category', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'case_nature', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // Gate 1 field (Addendum 7)
        register_post_meta( self::POST_TYPE, 'basis_of_identification', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // Gate 3 (Hearing) — which body heard the case. Determines
        // Appeal-stage eligibility. See class docblock.
        register_post_meta( self::POST_TYPE, 'heard_by', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // Gate 3 fact-finding fields (Addendum 7) — established before
        // any outcome; see class docblock.
        register_post_meta( self::POST_TYPE, 'identity_confirmed', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'occurrence_confirmed', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'correct_party_identified', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // Appeal stage fields (Addendum 9). Only meaningful when
        // heard_by = 'committee'.
        register_post_meta( self::POST_TYPE, 'appeal_request_date', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_post_meta( self::POST_TYPE, 'appeal_grounds', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'appeal_review_date', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_post_meta( self::POST_TYPE, 'appeal_reviewers', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_post_meta( self::POST_TYPE, 'appeal_outcome', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        // Gate 2 (Formal Notice) fields — designed in this document's
        // original three-gate sketch, never actually built until now.
        register_post_meta( self::POST_TYPE, 'notice_type', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'cure_period', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'delivery_method', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'date_sent', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Empty/unset until the certified mail receipt actually comes
        // back — this IS the proof-of-delivery gate from the original
        // design sketch.
        register_post_meta( self::POST_TYPE, 'return_receipt_date', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Gate 3 outcome — the conclusion drawn FROM the fact-finding
        // fields (Addendum 7), recorded as its own action via
        // record_outcome(), separate from record_hearing().
        register_post_meta( self::POST_TYPE, 'outcome', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_key',
        ] );

        register_post_meta( self::POST_TYPE, 'fine_amount', [
            'type'              => 'number',
            'single'            => true,
            'show_in_rest'      => false,
        ] );
    }

    /**
     * Open a new case. Creates the case post, then provisions the full
     * stage-folder cascade in Nextcloud — UNLESS starting in
     * pending_review (Addendum 8), a raw submission awaiting staff
     * review/decision to formally open a case. Real Nextcloud folders
     * are not created for a submission that might never be escalated
     * — see advance_case() for where folder creation happens instead
     * when a pending_review case is later escalated.
     *
     * $args expects: client_post_id, case_category (MUST be one of
     * VALID_CASE_CATEGORIES), case_nature (MUST be one of
     * VALID_CASE_NATURE — 'mediated' or 'fixed_infraction'),
     * case_title (short human label, e.g. "Barking dog - 123 Oak St"),
     * optionally basis_of_identification (Addendum 7), and optionally
     * initial_status — must be STATUS_PENDING_REVIEW or
     * STATUS_COMPLAINT_INTAKE (default) if provided; no other status
     * makes sense as a starting point.
     */
    public static function open_case( $tenant_id, array $args ) {
        $tenant_id      = intval( $tenant_id );
        $client_post_id = intval( $args['client_post_id'] ?? 0 );
        $case_category  = sanitize_key( $args['case_category'] ?? '' );
        $case_nature    = sanitize_key( $args['case_nature'] ?? '' );
        $case_title     = sanitize_text_field( $args['case_title'] ?? '' );
        $basis          = sanitize_key( $args['basis_of_identification'] ?? '' );
        $initial_status = sanitize_key( $args['initial_status'] ?? self::STATUS_COMPLAINT_INTAKE );

        if ( ! $tenant_id || ! $client_post_id || empty( $case_title ) ) {
            return new WP_Error( 'ddv_case_missing_fields', 'tenant_id, client_post_id, and case_title are required.' );
        }

        if ( ! in_array( $case_category, self::VALID_CASE_CATEGORIES, true ) ) {
            return new WP_Error(
                'ddv_case_invalid_category',
                "'{$case_category}' is not a valid case category.",
                [ 'valid_categories' => self::VALID_CASE_CATEGORIES ]
            );
        }

        if ( ! in_array( $case_nature, self::VALID_CASE_NATURE, true ) ) {
            return new WP_Error(
                'ddv_case_invalid_nature',
                "'{$case_nature}' is not a valid case_nature — must be 'mediated' or 'fixed_infraction'.",
                [ 'valid_values' => self::VALID_CASE_NATURE ]
            );
        }

        if ( $basis && ! in_array( $basis, self::VALID_BASIS_OF_IDENTIFICATION, true ) ) {
            return new WP_Error(
                'ddv_case_invalid_basis',
                "'{$basis}' is not a valid basis_of_identification.",
                [ 'valid_values' => self::VALID_BASIS_OF_IDENTIFICATION ]
            );
        }

        if ( ! in_array( $initial_status, [ self::STATUS_PENDING_REVIEW, self::STATUS_COMPLAINT_INTAKE ], true ) ) {
            return new WP_Error(
                'ddv_case_invalid_initial_status',
                "'{$initial_status}' is not a valid starting status — must be pending_review or complaint_intake."
            );
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_title'  => $case_title,
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'tenant_id', $tenant_id );
        update_post_meta( $post_id, 'client_post_id', $client_post_id );
        update_post_meta( $post_id, 'case_status', $initial_status );
        update_post_meta( $post_id, 'case_category', $case_category );
        update_post_meta( $post_id, 'case_nature', $case_nature );
        if ( $basis ) {
            update_post_meta( $post_id, 'basis_of_identification', $basis );
        }

        // Only provision real Nextcloud folders if this case is
        // formally open from the start. A pending_review submission
        // gets folders later, if/when it's escalated — see
        // advance_case().
        if ( $initial_status === self::STATUS_COMPLAINT_INTAKE ) {
            DDV_Vault_Connector::provision_case_folders( $tenant_id, $client_post_id, $post_id, $case_title, $case_category );
        }

        return $post_id;
    }

    /**
     * Record Gate 2 (Formal Notice) details. Designed in this
     * document's original three-gate sketch, never actually built
     * until now. return_receipt_date is deliberately optional here —
     * it's the proof-of-delivery gate, filled in later once the
     * certified mail receipt actually comes back, not at the moment
     * the notice is sent.
     *
     * $args expects: notice_type, cure_period, delivery_method,
     * date_sent — all required. return_receipt_date optional.
     */
    public static function record_notice( $case_post_id, array $args ) {
        $case_post_id     = intval( $case_post_id );
        $notice_type      = sanitize_key( $args['notice_type'] ?? '' );
        $cure_period      = sanitize_key( $args['cure_period'] ?? '' );
        $delivery_method  = sanitize_key( $args['delivery_method'] ?? '' );
        $date_sent        = sanitize_text_field( $args['date_sent'] ?? '' );
        $return_receipt   = sanitize_text_field( $args['return_receipt_date'] ?? '' );

        if ( ! in_array( $notice_type, self::VALID_NOTICE_TYPES, true ) ) {
            return new WP_Error( 'ddv_case_invalid_notice_type', "'{$notice_type}' is not a valid notice_type." );
        }

        if ( ! in_array( $cure_period, self::VALID_CURE_PERIODS, true ) ) {
            return new WP_Error( 'ddv_case_invalid_cure_period', "'{$cure_period}' is not a valid cure_period." );
        }

        if ( ! in_array( $delivery_method, self::VALID_DELIVERY_METHODS, true ) ) {
            return new WP_Error( 'ddv_case_invalid_delivery_method', "'{$delivery_method}' is not a valid delivery_method." );
        }

        if ( empty( $date_sent ) ) {
            return new WP_Error( 'ddv_case_missing_date_sent', 'date_sent is required.' );
        }

        update_post_meta( $case_post_id, 'notice_type', $notice_type );
        update_post_meta( $case_post_id, 'cure_period', $cure_period );
        update_post_meta( $case_post_id, 'delivery_method', $delivery_method );
        update_post_meta( $case_post_id, 'date_sent', $date_sent );

        if ( $return_receipt ) {
            update_post_meta( $case_post_id, 'return_receipt_date', $return_receipt );
        }

        return true;
    }

    /**
     * Record Gate 3's outcome — the conclusion drawn FROM the
     * fact-finding fields established by record_hearing() (Addendum
     * 7), recorded as a separate action since findings and the
     * decision based on them are logically distinct steps, even if
     * they happen in the same meeting. fine_amount is required when
     * the outcome actually imposes a fine.
     *
     * Does NOT yet check DDV_Client_Workspace::is_fine_schedule_registered()
     * before allowing fine_imposed — that Gate 3 validation check
     * (Addendum 8) is a separate, not-yet-wired piece of scope.
     */
    public static function record_outcome( $case_post_id, $outcome, $fine_amount = null ) {
        $case_post_id = intval( $case_post_id );
        $outcome      = sanitize_key( $outcome );

        if ( ! in_array( $outcome, self::VALID_OUTCOMES, true ) ) {
            return new WP_Error( 'ddv_case_invalid_outcome', "'{$outcome}' is not a valid outcome." );
        }

        $fine_required = in_array( $outcome, [ 'fine_imposed', 'fine_and_continued_monitoring' ], true );

        if ( $fine_required && ( $fine_amount === null || ! is_numeric( $fine_amount ) ) ) {
            return new WP_Error( 'ddv_case_missing_fine_amount', 'fine_amount is required and must be numeric when the outcome imposes a fine.' );
        }

        update_post_meta( $case_post_id, 'outcome', $outcome );

        if ( $fine_amount !== null && is_numeric( $fine_amount ) ) {
            update_post_meta( $case_post_id, 'fine_amount', floatval( $fine_amount ) );
        }

        return true;
    }

    /**
     * Record the outcome of Gate 3 (Hearing): which body heard the
     * case, plus the fact-finding results established BEFORE any
     * outcome is decided (Addendum 7) — the outcome is a conclusion
     * drawn from these findings, not a standalone judgment call.
     *
     * $findings (optional) may include any of: identity_confirmed,
     * occurrence_confirmed, correct_party_identified — each must be
     * one of VALID_FINDING_VALUES ('yes'/'no'/'disputed') if provided.
     *
     * Per §209.007, only a committee's decision can be appealed to the
     * board — a board-heard case has nowhere further to escalate
     * internally.
     */
    public static function record_hearing( $case_post_id, $heard_by, array $findings = [] ) {
        $case_post_id = intval( $case_post_id );
        $heard_by     = sanitize_key( $heard_by );

        if ( ! in_array( $heard_by, self::VALID_HEARD_BY, true ) ) {
            return new WP_Error( 'ddv_case_invalid_heard_by', "'{$heard_by}' must be 'committee' or 'board'." );
        }

        $finding_fields = [ 'identity_confirmed', 'occurrence_confirmed', 'correct_party_identified' ];

        foreach ( $finding_fields as $field ) {
            if ( ! isset( $findings[ $field ] ) ) {
                continue;
            }

            $value = sanitize_key( $findings[ $field ] );

            if ( ! in_array( $value, self::VALID_FINDING_VALUES, true ) ) {
                return new WP_Error(
                    'ddv_case_invalid_finding',
                    "'{$value}' is not a valid value for {$field} — must be yes, no, or disputed."
                );
            }
        }

        update_post_meta( $case_post_id, 'heard_by', $heard_by );

        foreach ( $finding_fields as $field ) {
            if ( isset( $findings[ $field ] ) ) {
                update_post_meta( $case_post_id, $field, sanitize_key( $findings[ $field ] ) );
            }
        }

        return true;
    }

    /**
     * Whether this case is eligible for the Appeal stage at all —
     * only true if a committee (not the full board) heard it.
     */
    public static function is_appeal_eligible( $case_post_id ) {
        return get_post_meta( intval( $case_post_id ), 'heard_by', true ) === 'committee';
    }

    /**
     * Advance a case to a new status. Validates against the full
     * VALID_STATUSES list (not just STAGE_FOLDERS), since
     * pending_review and closed_disputed are valid statuses without
     * their own folder entry.
     *
     * Special case (Addendum 8): if a case is escalating from
     * pending_review into complaint_intake for the first time, this is
     * where the real Nextcloud folder cascade actually gets created —
     * open_case() deliberately skipped it when the case started in
     * pending_review, since a submission that's never escalated
     * shouldn't get real folders at all.
     */
    public static function advance_case( $case_post_id, $new_status ) {
        $case_post_id = intval( $case_post_id );
        $new_status   = sanitize_key( $new_status );

        if ( ! in_array( $new_status, self::VALID_STATUSES, true ) ) {
            return new WP_Error( 'ddv_case_invalid_status', "'{$new_status}' is not a valid case status." );
        }

        if ( $new_status === self::STATUS_APPEAL_PENDING && ! self::is_appeal_eligible( $case_post_id ) ) {
            return new WP_Error( 'ddv_case_not_appeal_eligible', 'This case was heard directly by the board and has no internal appeal available (Texas Property Code Sec. 209.007(a)-(b)).' );
        }

        $current_status = get_post_meta( $case_post_id, 'case_status', true );

        if ( $current_status === self::STATUS_PENDING_REVIEW && $new_status === self::STATUS_COMPLAINT_INTAKE ) {
            $tenant_id      = get_post_meta( $case_post_id, 'tenant_id', true );
            $client_post_id = get_post_meta( $case_post_id, 'client_post_id', true );
            $case_category  = get_post_meta( $case_post_id, 'case_category', true );
            $case_title     = get_the_title( $case_post_id );

            DDV_Vault_Connector::provision_case_folders( $tenant_id, $client_post_id, $case_post_id, $case_title, $case_category );
        }

        return (bool) update_post_meta( $case_post_id, 'case_status', $new_status );
    }

    /**
     * Build a case's own subfolder name inside Legal_Enforcement/ — the
     * post ID keeps it unique even if two cases share a similar title.
     */
    public static function build_case_folder_name( $case_post_id, $case_title ) {
        $safe_title = preg_replace( '/[^a-zA-Z0-9]+/', '', $case_title );
        return intval( $case_post_id ) . '_' . $safe_title;
    }
}
