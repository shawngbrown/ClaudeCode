<?php

class DDV_Sorting_Shortcode {

    public function __construct() {
        add_shortcode('ddv_sorting', [ $this, 'render' ]);
    }

    public function render() {
        ob_start();
        include DDV_CORE_PATH . 'templates/sorting.php';
        return ob_get_clean();
    }
}

new DDV_Sorting_Shortcode();
