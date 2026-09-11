<?php

class DDV_Accreditation_Shortcode {

    public function __construct() {
        add_shortcode('ddv_accreditation', [ $this, 'render' ]);
    }

    public function render() {
        ob_start();
        include DDV_CORE_PATH . 'templates/accreditation.php';
        return ob_get_clean();
    }
}

new DDV_Accreditation_Shortcode();
