<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Document_Composer
 *
 * Implements Addendum 4's document-creation UX flow — Steps 1-3,
 * JIT-eligible branch only, per that addendum's own scoping guidance
 * (item 1: prove the flow against the real Violation Notice case
 * before generalizing; item 4: hold off on the non-JIT branch —
 * Template Locator, Existing Document Copy — until there's real
 * template library content to browse).
 *
 * Scope, stated plainly: this builds the real form/UX flow and wires
 * it to backend actions already built and proven (DDV_Enforcement_Case
 * ::record_notice(), DDV_Document::create_document()). It does NOT
 * generate real document text — that's the separate, still-unbuilt
 * JIT generation work. The document pointer this creates will show as
 * "missing" under DDV_Reconciliation until that real generation step
 * exists, which is honest, not a bug — this is exactly what document
 * 432 already represents.
 *
 * Only "Violation Notice" is wired to the JIT Template Form today —
 * the one real proof case. Any other document type shows a plain
 * "not yet available" message rather than attempting to build the
 * non-JIT branch prematurely.
 */
class DDV_Document_Composer {

    public static function init() {
        add_shortcode( 'ddv_new_document', [ __CLASS__, 'render' ] );
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to create a document.</p>';
        }

        $case_id = intval( $_GET['case_id'] ?? 0 );

        if ( ! $case_id || get_post_type( $case_id ) !== DDV_Enforcement_Case::POST_TYPE ) {
            return '<p>No valid case selected.</p>';
        }

        $case_category = get_post_meta( $case_id, 'case_category', true );
        $case_title    = get_the_title( $case_id );

        if ( ! $case_category ) {
            return '<p>This case has no category set.</p>';
        }

        // Step 3 form submitted — process and finish, don't re-render
        // the form.
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ddv_generate_document'] ) ) {
            return self::handle_generate( $case_id, $case_category, $case_title );
        }

        // Step 1 submitted (document type chosen) — show Step 2+3.
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ddv_document_type_submit'] ) ) {
            $document_type = sanitize_text_field( $_POST['ddv_document_type'] ?? '' );
            return self::render_step_2_and_3( $case_id, $case_category, $case_title, $document_type );
        }

        // First load — Step 1.
        return self::render_step_1( $case_id, $case_category );
    }

    /**
     * Step 1 — Document Type dropdown, context-scoped to the case's
     * own category. Pulls the real document-type list for that
     * category from the real compliance data, not a hardcoded list.
     */
    protected static function render_step_1( $case_id, $case_category ) {
        $data = DDV_Compliance_Library::get_compliance_data( 'hoa_poa' );

        $document_types = $data['categories']['legal_enforcement'][ $case_category ] ?? [];

        if ( empty( $document_types ) ) {
            return '<p>No document types are configured for this case\'s category.</p>';
        }

        ob_start();
        ?>
        <div class="ddv-document-composer">
            <h3>New Document</h3>
            <p>Select the type of document to create for this case.</p>
            <form method="post">
                <?php wp_nonce_field( 'ddv_new_document', 'ddv_new_document_nonce' ); ?>
                <input type="hidden" name="ddv_case_id" value="<?php echo esc_attr( $case_id ); ?>" />

                <label>Document Type</label>
                <select name="ddv_document_type" required>
                    <option value="">Select...</option>
                    <?php foreach ( $document_types as $type ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="ddv_document_type_submit">Next</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Step 2 (template resolution, surfaced not hidden) + Step 3
     * (the JIT/non-JIT split). Only "Violation Notice" is wired to a
     * real JIT Template Form today — see class docblock.
     */
    protected static function render_step_2_and_3( $case_id, $case_category, $case_title, $document_type ) {
        $data      = DDV_Compliance_Library::get_compliance_data( 'hoa_poa' );
        $citations = $data['document_citations'][ $document_type ] ?? [];

        ob_start();
        ?>
        <div class="ddv-document-composer">
            <h3><?php echo esc_html( $document_type ); ?></h3>

            <?php if ( ! empty( $citations ) ) : ?>
                <p style="font-size:0.9em;color:#666;">
                    <strong>Governing standard:</strong>
                    <?php echo esc_html( implode( '; ', $citations ) ); ?>
                </p>
            <?php endif; ?>

            <?php if ( $document_type === 'Violation Notice' ) : ?>
                <?php echo self::render_jit_template_form( $case_id, $case_category, $case_title, $document_type ); ?>
            <?php else : ?>
                <p>The guided form for "<?php echo esc_html( $document_type ); ?>" isn't available yet — only Violation Notice is wired up as today's proof case. This document type will use the Template Locator / Existing Document Copy flow once that's built (Addendum 4).</p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Step 3, JIT-eligible branch. Fields already captured on the
     * case record appear pre-filled and read-only — never re-entered.
     * Remaining fields use the narrowest appropriate widget:
     * dropdowns for constrained choices, matching Gate 2's real field
     * design.
     *
     * KNOWN GAP, flagged not hidden: the original Gate 2 design (this
     * document's very first version) included a "Violation
     * description" field that was never actually built into
     * DDV_Enforcement_Case — this form has nowhere to collect it.
     * Worth a small follow-up addition, not solved here.
     */
    protected static function render_jit_template_form( $case_id, $case_category, $case_title, $document_type ) {
        ob_start();
        ?>
        <form method="post">
            <?php wp_nonce_field( 'ddv_new_document', 'ddv_new_document_nonce' ); ?>
            <input type="hidden" name="ddv_case_id" value="<?php echo esc_attr( $case_id ); ?>" />
            <input type="hidden" name="ddv_document_type" value="<?php echo esc_attr( $document_type ); ?>" />

            <p><strong>Case:</strong> <?php echo esc_html( $case_title ); ?> <em>(pre-filled, read-only)</em></p>
            <p><strong>Category:</strong> <?php echo esc_html( ucfirst( $case_category ) ); ?> <em>(pre-filled, read-only)</em></p>

            <label>Cure Period</label>
            <select name="ddv_cure_period" required>
                <option value="">Select...</option>
                <?php foreach ( DDV_Enforcement_Case::VALID_CURE_PERIODS as $period ) : ?>
                    <option value="<?php echo esc_attr( $period ); ?>"><?php echo esc_html( str_replace( '_', ' ', $period ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <label>Delivery Method</label>
            <select name="ddv_delivery_method" required>
                <option value="">Select...</option>
                <?php foreach ( DDV_Enforcement_Case::VALID_DELIVERY_METHODS as $method ) : ?>
                    <option value="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( str_replace( '_', ' ', $method ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <label>Date Sent</label>
            <input type="date" name="ddv_date_sent" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required />

            <button type="submit" name="ddv_generate_document">Generate</button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handles the final "Generate" submission. Calls the real,
     * already-proven backend actions — record_notice() and
     * create_document() — but does NOT generate real document text.
     * The resulting DDV_Document pointer will correctly show as
     * "missing" under DDV_Reconciliation until real JIT generation
     * exists to actually place a file at nc_path.
     */
    protected static function handle_generate( $case_id, $case_category, $case_title ) {
        if ( ! isset( $_POST['ddv_new_document_nonce'] ) || ! wp_verify_nonce( $_POST['ddv_new_document_nonce'], 'ddv_new_document' ) ) {
            return '<p>Security check failed. Please try again.</p>';
        }

        $document_type   = sanitize_text_field( $_POST['ddv_document_type'] ?? '' );
        $cure_period     = sanitize_key( $_POST['ddv_cure_period'] ?? '' );
        $delivery_method = sanitize_key( $_POST['ddv_delivery_method'] ?? '' );
        $date_sent       = sanitize_text_field( $_POST['ddv_date_sent'] ?? '' );

        $tenant_id      = get_post_meta( $case_id, 'tenant_id', true );
        $client_post_id = get_post_meta( $case_id, 'client_post_id', true );

        $notice_result = DDV_Enforcement_Case::record_notice( $case_id, [
            'notice_type'     => 'violation_notice',
            'cure_period'     => $cure_period,
            'delivery_method' => $delivery_method,
            'date_sent'       => $date_sent,
        ] );

        if ( is_wp_error( $notice_result ) ) {
            return '<p>Could not record notice: ' . esc_html( $notice_result->get_error_message() ) . '</p>';
        }

        $pcmf_code   = get_post_meta( $client_post_id, 'pcmf_code', true );
        $client_name = get_the_title( $client_post_id );
        $safe_client = preg_replace( '/[^a-zA-Z0-9]+/', '', $client_name );
        $case_folder = DDV_Enforcement_Case::build_case_folder_name( $case_id, $case_title );
        $tenant      = DDV_Tenant::get_tenant( $tenant_id );
        $tenant_folder = 'Tenant_' . intval( $tenant_id ) . '_' . preg_replace( '/[^a-zA-Z0-9]+/', '', $tenant['business_name'] );

        $nc_path = "{$tenant_folder}/Clients/{$pcmf_code}_{$safe_client}/Legal Enforcement/" . ucfirst( $case_category ) . "/{$case_folder}/02_Notice_Sent/{$document_type}.pdf";

        $document_id = DDV_Document::create_document( $tenant_id, [
            'client_post_id' => $client_post_id,
            'case_post_id'   => $case_id,
            'category'       => 'legal_enforcement',
            'subcategory'    => $case_category,
            'document_type'  => sanitize_key( $document_type ),
            'title'          => $document_type . ' — ' . $case_title,
            'nc_path'        => $nc_path,
        ] );

        if ( is_wp_error( $document_id ) ) {
            return '<p>Notice recorded, but document pointer creation failed: ' . esc_html( $document_id->get_error_message() ) . '</p>';
        }

        ob_start();
        ?>
        <div class="ddv-document-composer">
            <h3>Notice Recorded</h3>
            <p>The notice details have been saved to the case, and a document pointer has been created.</p>
            <p style="color:#e65100;"><strong>Note:</strong> the actual document file has not been generated yet — that's the separate JIT-generation step, not yet built. This pointer will correctly show as "Missing" under Reconciliation until then.</p>
        </div>
        <?php
        return ob_get_clean();
    }
}