<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Dashboard_Controller {

    public static function get_compliance_overview() {
        $lib = new DDV_Compliance_Library();
        return $lib->get_summary();
    }
}
