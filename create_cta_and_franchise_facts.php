<?php
/**
 * Creates 2 new Reference Facts and their 2 Document Types:
 *  - 31 U.S.C. Sec. 5336 (Corporate Transparency Act / BOI reporting)
 *    -> "FinCEN Beneficial Ownership Filings"
 *  - Tex. Tax Code Sec. 171.0002 (taxable entity classification)
 *    -> "Franchise Tax Filings"
 *
 * Run via WP-CLI:
 *   wp eval-file create_cta_and_franchise_facts.php
 */

// ---------- Fact 1: Corporate Transparency Act / BOI ----------
$cta_fact_id = wp_insert_post( [
    'post_type'   => 'ddv_reference_fact',
    'post_status' => 'publish',
    'post_title'  => 'Corporate Transparency Act Beneficial Ownership Reporting',
    'post_name'   => 'us-code-31-5336-cta-beneficial-ownership',
    'post_content' => "31 U.S.C. Sec. 5336 is the Corporate Transparency Act (CTA), which originally required most US business entities to report their beneficial owners to FinCEN. As of a Final Rule effective August 14, 2026, domestic reporting companies are PERMANENTLY exempt from this requirement -- FinCEN's final rule adopted and made permanent the March 2025 interim exemption. Only entities formed under the law of a foreign country and registered to do business in a US state or tribal jurisdiction remain subject to beneficial ownership reporting, and only as to their non-US-person beneficial owners.\n\nThe CTA's underlying constitutionality is still being litigated at the US Supreme Court (National Small Business United v. Bessent; Texas Top Cop Shop v. Blanche, both docketed 2026), but that litigation does not currently affect the validity of the August 2026 exemption -- it concerns the statute's constitutional basis generally, not this specific regulatory carve-out.\n\nPractical effect: for the vast majority of US-domestic small business clients, no BOI filing is currently required, and FinCEN is in the process of deleting previously-submitted US-person BOI data from its database.",
], true );

if ( is_wp_error( $cta_fact_id ) ) {
    WP_CLI::error( 'CTA fact failed: ' . $cta_fact_id->get_error_message() );
}
update_post_meta( $cta_fact_id, 'citation',       '31 U.S.C. Sec. 5336' );
update_post_meta( $cta_fact_id, 'source_url',     'https://www.fincen.gov/boi' );
update_post_meta( $cta_fact_id, 'source_type',    'statute' );
update_post_meta( $cta_fact_id, 'confirmed_date', '2026-09-12' );
update_post_meta( $cta_fact_id, 'superseded_by',  0 );
WP_CLI::success( "CTA Fact created - post ID: $cta_fact_id" );


// ---------- Fact 2: Tex. Tax Code Sec. 171.0002 ----------
$tax_fact_id = wp_insert_post( [
    'post_type'   => 'ddv_reference_fact',
    'post_status' => 'publish',
    'post_title'  => 'Texas Franchise Tax - Taxable Entity Classification',
    'post_name'   => 'tex-tax-code-171-0002-taxable-entity',
    'post_content' => "Tex. Tax Code Sec. 171.0002 defines which business entities are 'taxable entities' subject to Texas franchise tax. Subsection (a) lists most entity types -- including corporations, LLCs, limited partnerships, and limited liability partnerships -- as taxable entities by default, with no ownership-based exception available.\n\nSubsection (b)(2) creates one narrow exception: a general partnership is NOT a taxable entity if its direct ownership is composed entirely of natural persons AND its liability is not limited under any state's statute, including by registering as an LLP. Registering as an LLP -- or having any non-natural-person partner -- forfeits this exception entirely; the partnership then becomes a taxable entity the same as any other, with franchise tax and Public Information Report (PIR) filings required regardless of partner composition.",
], true );

if ( is_wp_error( $tax_fact_id ) ) {
    WP_CLI::error( 'Tax Code fact failed: ' . $tax_fact_id->get_error_message() );
}
update_post_meta( $tax_fact_id, 'citation',       'Tex. Tax Code Sec. 171.0002' );
update_post_meta( $tax_fact_id, 'source_url',     'https://statutes.capitol.texas.gov/Docs/TX/htm/TX.171.htm#171.0002' );
update_post_meta( $tax_fact_id, 'source_type',    'statute' );
update_post_meta( $tax_fact_id, 'confirmed_date', '2026-09-12' );
update_post_meta( $tax_fact_id, 'superseded_by',  0 );
WP_CLI::success( "Tax Code Fact created - post ID: $tax_fact_id" );


// ---------- Document Type 1: FinCEN Beneficial Ownership Filings ----------
$cta_doctype_id = wp_insert_post( [
    'post_type'   => 'ddv_document_type',
    'post_status' => 'publish',
    'post_title'  => 'FinCEN Beneficial Ownership Filings',
], true );

if ( is_wp_error( $cta_doctype_id ) ) {
    WP_CLI::error( 'CTA doc type failed: ' . $cta_doctype_id->get_error_message() );
}
update_post_meta( $cta_doctype_id, 'field_list', json_encode( [
    'entity_name',
    'is_foreign_entity',
    'boi_filing_required',
    'date_of_document',
] ) );
update_post_meta( $cta_doctype_id, 'boilerplate_reference', json_encode( [
    'us-code-31-5336-cta-beneficial-ownership',
] ) );
update_post_meta( $cta_doctype_id, 'variable_field_mapping', json_encode( [
    'entity_name'          => 'auto',
    'is_foreign_entity'    => 'manual',
    'boi_filing_required'  => 'manual',
    'date_of_document'     => 'auto',
] ) );
WP_CLI::success( "CTA Document Type created - post ID: $cta_doctype_id" );


// ---------- Document Type 2: Franchise Tax Filings ----------
$tax_doctype_id = wp_insert_post( [
    'post_type'   => 'ddv_document_type',
    'post_status' => 'publish',
    'post_title'  => 'Franchise Tax Filings',
], true );

if ( is_wp_error( $tax_doctype_id ) ) {
    WP_CLI::error( 'Franchise Tax doc type failed: ' . $tax_doctype_id->get_error_message() );
}
update_post_meta( $tax_doctype_id, 'field_list', json_encode( [
    'entity_name',
    'entity_type',
    'natural_person_owned_only',
    'llp_registered',
    'taxable_entity_status',
    'date_of_document',
] ) );
update_post_meta( $tax_doctype_id, 'boilerplate_reference', json_encode( [
    'tex-tax-code-171-0002-taxable-entity',
] ) );
update_post_meta( $tax_doctype_id, 'variable_field_mapping', json_encode( [
    'entity_name'                 => 'auto',
    'entity_type'                 => 'auto',
    'natural_person_owned_only'   => 'manual',
    'llp_registered'              => 'manual',
    'taxable_entity_status'       => 'manual',
    'date_of_document'            => 'auto',
] ) );
WP_CLI::success( "Franchise Tax Document Type created - post ID: $tax_doctype_id" );

WP_CLI::log( "\nAll done. Fact slugs for JSON migration:" );
WP_CLI::log( "  CTA:         us-code-31-5336-cta-beneficial-ownership" );
WP_CLI::log( "  Tax Code:    tex-tax-code-171-0002-taxable-entity" );