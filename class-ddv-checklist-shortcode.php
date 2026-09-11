<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Checklist_Shortcode {

    public static function init() {
        add_shortcode( 'ddv_checklist', [ __CLASS__, 'render_shortcode' ] );
    }

    public static function render_shortcode( $atts ) {

        $atts = shortcode_atts([
            'entity' => 'hoa_poa'
        ], $atts );

        return DDV_Checklist_Renderer::render( $atts['entity'] );
    }
}
