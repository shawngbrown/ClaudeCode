<?php

class DDV_Dashboard_Shortcode {

    public function __construct() {
        add_shortcode('ddv_dashboard', [ $this, 'render' ]);
    }

    public function render() {
        ob_start();
        include DDV_CORE_PATH . 'templates/dashboard.php';
        return ob_get_clean();
    }
}

new DDV_Dashboard_Shortcode();
