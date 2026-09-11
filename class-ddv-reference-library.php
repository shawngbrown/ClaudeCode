<?php
/**
 * DDV Reference Library
 * Registers the ddv_reference_fact custom post type -- the citable,
 * locally-hosted fact library described in Addendum 5.
 *
 * Each post is simultaneously:
 *  - A human-readable page at docdocvault.com/reference/{slug}/
 *  - Machine-readable JSON via the WP REST API (show_in_rest => true)
 *
 * Deploy: save as
 *   wp-content/plugins/docdocvault-core/includes/class-ddv-reference-library.php
 * and require it from docdocvault-core.php's existing include chain,
 * the same way class-ddv-vault-connector.php etc. are already loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // no direct access
}

// ─────────────────────────────────────────────
// REGISTER POST TYPE: ddv_reference_fact
// ─────────────────────────────────────────────
function ddv_register_reference_fact_cpt() {
    register_post_type( 'ddv_reference_fact', [
        'labels' => [
            'name'          => 'Reference Facts',
            'singular_name' => 'Reference Fact',
            'add_new_item'  => 'Add New Reference Fact',
            'edit_item'     => 'Edit Reference Fact',
            'all_items'     => 'All Reference Facts',
        ],
        'public'       => true,
        'show_in_rest' => true,          // REST API exposure -- this is what
                                          // makes the JSON side "free"
        'rest_base'    => 'reference-facts',
        'has_archive'  => true,
        'rewrite'      => [ 'slug' => 'reference' ],
        'menu_icon'    => 'dashicons-media-document',
        'supports'     => [ 'title', 'editor', 'custom-fields' ],
        'show_in_menu' => true,
    ] );
}
add_action( 'init', 'ddv_register_reference_fact_cpt' );

// ─────────────────────────────────────────────
// REGISTER META FIELDS (each exposed via REST automatically)
// ─────────────────────────────────────────────
// ─────────────────────────────────────────────
// CITATION REFERENCE RESOLVER
//
// document_citations arrays (in the entity/industry JSON data files)
// can now contain either:
//   - a raw citation string (legacy shape, unchanged) -- returned as-is
//   - a reference marker "ref:{fact-slug}" -- resolved here by looking
//     up the matching ddv_reference_fact post and returning its
//     'citation' meta value, so the display text is never just a bare
//     slug string.
//
// Backward-compatible by design: nothing that already works needs to
// change until a given citation entry is deliberately migrated to the
// ref: form.
// ─────────────────────────────────────────────
function ddv_resolve_citation_ref( $citation ) {
    if ( ! is_string( $citation ) || strpos( $citation, 'ref:' ) !== 0 ) {
        return $citation; // not a reference marker -- return unchanged
    }

    $slug = substr( $citation, 4 );
    $post = get_page_by_path( $slug, OBJECT, 'ddv_reference_fact' );

    if ( ! $post ) {
        // Fact not found (bad slug, or fact deleted) -- fail loud in the
        // display rather than silently, so a broken reference gets caught.
        return '[unresolved reference: ' . esc_html( $slug ) . ']';
    }

    return get_post_meta( $post->ID, 'citation', true );
}

/**
 * Resolve every entry in a document_citations[$document_type] array,
 * so calling code never has to know whether an entry is legacy raw
 * text or a ref: pointer.
 */
function ddv_resolve_citations_array( $citations ) {
    if ( ! is_array( $citations ) ) {
        return $citations;
    }
    return array_map( 'ddv_resolve_citation_ref', $citations );
}

function ddv_register_reference_fact_meta() {
    $fields = [
        'citation'       => 'string',  // e.g. "Tex. Prop. Code Sec. 209.006"
        'source_url'     => 'string',  // e.g. statutes.capitol.texas.gov/...
        'source_type'    => 'string',  // statute | agency_guidance | client_supplied
        'confirmed_date' => 'string',  // "2026-09-05"
        'superseded_by'  => 'integer', // post ID of a newer fact, or 0
    ];

    foreach ( $fields as $key => $type ) {
        register_post_meta( 'ddv_reference_fact', $key, [
            'type'              => $type,
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => ( $type === 'integer' )
                ? 'absint'
                : 'sanitize_text_field',
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
}
add_action( 'init', 'ddv_register_reference_fact_meta' );

// ─────────────────────────────────────────────
// ADMIN COLUMNS -- surface the key fields in the admin list table
// ─────────────────────────────────────────────
function ddv_reference_fact_admin_columns( $columns ) {
    $columns['citation']    = 'Citation';
    $columns['source_type'] = 'Source Type';
    $columns['confirmed']   = 'Confirmed';
    return $columns;
}
add_filter( 'manage_ddv_reference_fact_posts_columns', 'ddv_reference_fact_admin_columns' );

function ddv_reference_fact_admin_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'citation':
            echo esc_html( get_post_meta( $post_id, 'citation', true ) );
            break;
        case 'source_type':
            echo esc_html( get_post_meta( $post_id, 'source_type', true ) );
            break;
        case 'confirmed':
            echo esc_html( get_post_meta( $post_id, 'confirmed_date', true ) );
            break;
    }
}
add_action( 'manage_ddv_reference_fact_posts_custom_column', 'ddv_reference_fact_admin_column_content', 10, 2 );


// ─────────────────────────────────────────────
// REGISTER POST TYPE: ddv_document_type
// (the JIT-eligible document-type entry from Addendum 4/5)
// ─────────────────────────────────────────────
function ddv_register_document_type_cpt() {
    register_post_type( 'ddv_document_type', [
        'labels' => [
            'name'          => 'Document Types',
            'singular_name' => 'Document Type',
            'add_new_item'  => 'Add New Document Type',
            'edit_item'     => 'Edit Document Type',
            'all_items'     => 'All Document Types',
        ],
        'public'       => false,          // internal config, not a public page
        'show_ui'      => true,
        'show_in_rest' => true,
        'rest_base'    => 'document-types',
        'supports'     => [ 'title', 'custom-fields' ],
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-media-text',
    ] );
}
add_action( 'init', 'ddv_register_document_type_cpt' );

function ddv_register_document_type_meta() {
    $fields = [
        'field_list'             => 'string', // JSON-encoded array of fillable fields
        'boilerplate_reference'  => 'string', // JSON-encoded array of reference_fact slugs
        'variable_field_mapping' => 'string', // JSON-encoded map: field -> auto|manual
    ];

    foreach ( $fields as $key => $type ) {
        register_post_meta( 'ddv_document_type', $key, [
            'type'              => $type,
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_textarea_field',
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
}
add_action( 'init', 'ddv_register_document_type_meta' );