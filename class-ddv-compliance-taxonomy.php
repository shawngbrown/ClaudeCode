<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Compliance_Taxonomy
 *
 * Registers ddv_compliance_category / ddv_document_type — deliberately a
 * SEPARATE taxonomy pair from DDV_Client_Workspace's business-type/client
 * taxonomy, so the two never collide.
 *
 * Category = compliance section (Identity, Governance, Financial, etc.),
 * hierarchical — supports nested sub-categories (legal_enforcement's
 * assessment/fines/fees/etc. become real child terms of a "Legal
 * Enforcement" parent, not a flattened list).
 *
 * Document Type = individual item within a category, flat (tag-like).
 * Deliberately genuinely flat and shared across categories/entities where
 * names repeat (e.g. "ACC Guidelines" appears in more than one hoa_poa.json
 * category) — that's treated as correct reuse, not a collision to avoid.
 *
 * WordPress has no native taxonomy-to-taxonomy relationship, so which
 * categories a given document_type term belongs to is tracked explicitly
 * via term meta ('category_ids'). Both term types also accumulate an
 * 'entity_types' term meta array, so shared terms record every entity
 * JSON that references them as more entity files get authored later.
 *
 * Attaches to the future per-document CPT (build-order step 10, not yet
 * registered) — registering the taxonomy now against that slug is
 * harmless; WordPress simply won't show it anywhere in the admin UI
 * meta-box sense until that CPT exists, but terms can be seeded and
 * queried immediately.
 */
class DDV_Compliance_Taxonomy {

    const TAX_CATEGORY = 'ddv_compliance_category';
    const TAX_DOC_TYPE  = 'ddv_document_type';

    // Forward reference — the per-document CPT is build-order step 10.
    const FUTURE_DOCUMENT_POST_TYPE = 'ddv_document';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_taxonomies' ] );
    }

    public static function register_taxonomies() {
        register_taxonomy( self::TAX_CATEGORY, [ self::FUTURE_DOCUMENT_POST_TYPE ], [
            'labels' => [
                'name'          => 'Compliance Categories',
                'singular_name' => 'Compliance Category',
            ],
            'hierarchical' => true,
            'show_ui'      => true,
            'show_in_rest' => true,
            'public'       => false,
            'rewrite'      => false,
        ] );

        register_taxonomy( self::TAX_DOC_TYPE, [ self::FUTURE_DOCUMENT_POST_TYPE ], [
            'labels' => [
                'name'          => 'Document Types',
                'singular_name' => 'Document Type',
            ],
            'hierarchical' => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'public'       => false,
            'rewrite'      => false,
        ] );
    }

    /**
     * Seed (or re-seed, idempotently) taxonomy terms from a single
     * entity's compliance JSON, via the same DDV_Compliance_Library
     * loader the [ddv_checklist] shortcode already uses.
     *
     * Returns an array summary (['categories' => N, 'document_types' => N])
     * or WP_Error if the entity has no compliance data on file.
     */
    public static function seed_from_entity( $entity_type ) {
        $entity_type = sanitize_key( $entity_type );
        $data        = DDV_Compliance_Library::get_compliance_data( $entity_type );

        if ( empty( $data['categories'] ) ) {
            return new WP_Error( 'ddv_taxonomy_no_data', "No compliance data found for entity type '{$entity_type}'." );
        }

        $counts = [ 'categories' => 0, 'document_types' => 0 ];

        foreach ( $data['categories'] as $category_key => $items ) {
            self::seed_category( $category_key, $items, 0, $entity_type, $counts );
        }

        return $counts;
    }

    /**
     * Recursive: creates (or reuses) one category term, then either
     * recurses into nested sub-categories (legal_enforcement's shape) or
     * seeds the flat list of document-type terms under it.
     */
    protected static function seed_category( $key, $items, $parent_term_id, $entity_type, array &$counts ) {
        $label        = self::humanize( $key );
        $category_id  = self::get_or_create_term( $label, self::TAX_CATEGORY, $parent_term_id );

        if ( is_wp_error( $category_id ) ) {
            return $category_id;
        }

        self::append_entity_type_meta( $category_id, self::TAX_CATEGORY, $entity_type );
        $counts['categories']++;

        if ( self::is_associative( $items ) ) {
            // Nested sub-categories (legal_enforcement's shape) — each
            // sub-key becomes a real child term of this category, not a
            // flattened prefix.
            foreach ( $items as $sub_key => $sub_items ) {
                self::seed_category( $sub_key, $sub_items, $category_id, $entity_type, $counts );
            }
            return $category_id;
        }

        // Flat list of document-type name strings.
        foreach ( (array) $items as $doc_type_name ) {
            $doc_type_id = self::get_or_create_term( $doc_type_name, self::TAX_DOC_TYPE, 0 );

            if ( is_wp_error( $doc_type_id ) ) {
                continue;
            }

            self::append_entity_type_meta( $doc_type_id, self::TAX_DOC_TYPE, $entity_type );
            self::append_category_meta( $doc_type_id, $category_id );
            $counts['document_types']++;
        }

        return $category_id;
    }

    /**
     * Idempotent term lookup/creation — running the seeder twice never
     * creates duplicate terms.
     */
    protected static function get_or_create_term( $name, $taxonomy, $parent_id ) {
        $existing = term_exists( $name, $taxonomy, $parent_id ?: 0 );

        if ( $existing ) {
            return (int) $existing['term_id'];
        }

        $result = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent_id ?: 0 ] );

        if ( is_wp_error( $result ) ) {
            // 'term_exists' can still fire here in edge cases (e.g. a
            // same-named term with a different parent) — recover the ID
            // rather than treat it as a hard failure.
            if ( $result->get_error_code() === 'term_exists' ) {
                return (int) $result->get_error_data();
            }
            return $result;
        }

        return (int) $result['term_id'];
    }

    /**
     * Record which entity types reference a given term, accumulating
     * across multiple seed runs rather than overwriting.
     */
    protected static function append_entity_type_meta( $term_id, $taxonomy, $entity_type ) {
        $existing = get_term_meta( $term_id, 'entity_types', true );
        $existing = is_array( $existing ) ? $existing : [];

        if ( ! in_array( $entity_type, $existing, true ) ) {
            $existing[] = $entity_type;
            update_term_meta( $term_id, 'entity_types', $existing );
        }
    }

    /**
     * Record which compliance-category term IDs a document-type term
     * belongs to. WordPress has no native cross-taxonomy relationship,
     * so this term meta is the mechanism anything downstream (the
     * reconciliation engine, a category-filtered doc-type dropdown) reads.
     */
    protected static function append_category_meta( $doc_type_id, $category_id ) {
        $existing = get_term_meta( $doc_type_id, 'category_ids', true );
        $existing = is_array( $existing ) ? $existing : [];

        if ( ! in_array( $category_id, $existing, true ) ) {
            $existing[] = $category_id;
            update_term_meta( $doc_type_id, 'category_ids', $existing );
        }
    }

    /**
     * True if $arr is an associative array (nested sub-categories) rather
     * than a flat, numerically-indexed list of document-type strings.
     * Same logic already used in DDV_Checklist_Renderer for the identical
     * legal_enforcement shape.
     */
    protected static function is_associative( $arr ) {
        if ( ! is_array( $arr ) || empty( $arr ) ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    /**
     * snake_case -> readable label, e.g. "legal_enforcement" ->
     * "Legal Enforcement". Mirrors DDV_Checklist_Renderer::humanize().
     */
    protected static function humanize( $key ) {
        return ucwords( str_replace( '_', ' ', $key ) );
    }
}
