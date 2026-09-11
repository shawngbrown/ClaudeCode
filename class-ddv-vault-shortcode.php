<?php

class DDV_Vault_Shortcode {

    public function __construct() {
        add_shortcode('ddv_vault', [ $this, 'render' ]);
    }

    public function render() {
        ob_start();
        include DDV_CORE_PATH . 'templates/vault.php';
        return ob_get_clean();
    }
}

new DDV_Vault_Shortcode();
