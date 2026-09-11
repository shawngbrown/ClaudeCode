<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Checklist_Renderer {

    public static function render( $entity ) {

        // Load compliance library
        if ( ! class_exists( 'DDV_Compliance_Library' ) ) {
            return '<p>Compliance library not found.</p>';
        }

        $data = DDV_Compliance_Library::get_checklist( $entity );

        if ( empty( $data ) ) {
            return '<p>No checklist data found for entity: ' . esc_html( $entity ) . '</p>';
        }

        $output = '<div class="ddv-checklist">';

        foreach ( $data['categories'] as $category => $items ) {
            $output .= self::render_category( $category, $items );
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Render one category. Handles both the normal shape (a flat list of
     * item strings) and a nested shape (a category made of sub-categories,
     * e.g. legal_enforcement -> assessment/fines/fees/... each with their
     * own list of items).
     */
    protected static function render_category( $label, $items, $heading_level = 3 ) {
        $output  = '<h' . $heading_level . '>' . esc_html( self::humanize( $label ) ) . '</h' . $heading_level . '>';

        // Nested case: an associative array of sub-categories, each itself
        // a list of strings (or further nesting).
        if ( self::is_associative( $items ) ) {
            $output .= '<div class="ddv-checklist-subgroup">';
            foreach ( $items as $sub_label => $sub_items ) {
                $output .= self::render_category( $sub_label, $sub_items, $heading_level + 1 );
            }
            $output .= '</div>';
            return $output;
        }

        // Normal case: a flat list of item strings.
        $output .= '<ul>';
        foreach ( (array) $items as $item ) {
            $output .= '<li>' . esc_html( $item ) . '</li>';
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * True if $arr is an associative array (sub-categories) rather than a
     * plain numerically-indexed list of item strings.
     */
    protected static function is_associative( $arr ) {
        if ( ! is_array( $arr ) || empty( $arr ) ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    /**
     * Turn a snake_case category key into a readable heading,
     * e.g. "legal_enforcement" -> "Legal Enforcement".
     */
    protected static function humanize( $key ) {
        return ucwords( str_replace( '_', ' ', $key ) );
    }
}
