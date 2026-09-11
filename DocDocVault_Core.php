<?php
/**
 * Plugin Name: DocDocVault Core
 * Description: Core plugin for DocDocVault - onboarding, vault connection,
 *              sorting engine, compliance, tenant management.
 * Version: 0.1.0
 * Author: SB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DDV_CORE_VERSION', '0.1.0' );
define( 'DDV_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DDV_CORE_URL', plugin_dir_url( __FILE__ ) );

// Backend engines

// Checklist system (load FIRST)
require_once DDV_CORE_PATH . 'includes/class-ddv-checklist-renderer.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-checklist-shortcode.php';

// Core modules
require_once DDV_CORE_PATH . 'includes/class-ddv-onboarding.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-vault-connector.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-sorting-engine.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-compliance-library.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-tenant-manager.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-tenant.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-invitation.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-access-control.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-pcmf.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-client-workspace.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-compliance-taxonomy.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-client-onboarding.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-client-index.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-enforcement-case.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-document.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-reconciliation.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-document-composer.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-reference-library.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-change-monitor.php';

// Dashboard controller (NEW)
require_once DDV_CORE_PATH . 'includes/class-ddv-dashboard-controller.php';

// Frontend shortcode renderers
// NOTE: class-ddv-onboarding-shortcode.php removed — it registered a second,
// competing [ddv_onboarding] shortcode that collided with DDV_Onboarding::init().
// The real 6-step wizard lives in class-ddv-onboarding.php only.
//require_once DDV_CORE_PATH . 'includes/class-ddv-login-shortcode.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-accreditation-shortcode.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-dashboard-shortcode.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-vault-shortcode.php';
require_once DDV_CORE_PATH . 'includes/class-ddv-sorting-shortcode.php';

class DocDocVault_Core {

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init_modules' ], 5 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // POA module wiring
        add_action( 'plugins_loaded', [ $this, 'wire_poa_module' ], 20 );
    }

    public function init_modules() {
        DDV_Onboarding::init();
        DDV_Vault_Connector::init();
        DDV_Sorting_Engine::init();
        DDV_Compliance_Library::init();
        DDV_Tenant_Manager::init();
        DDV_Checklist_Shortcode::init();
        DDV_Invitation::init();
        DDV_Access_Control::init();
        DDV_Client_Workspace::init();
        DDV_Compliance_Taxonomy::init();
        DDV_Client_Index::init();
        DDV_Enforcement_Case::init();
        DDV_Document::init();
        DDV_Reconciliation::init();
        DDV_Document_Composer::init();
    }

    public function register_rest_routes() {
        DDV_Onboarding::register_rest_routes();
        DDV_Vault_Connector::register_rest_routes();
        DDV_Sorting_Engine::register_rest_routes();
        DDV_Compliance_Library::register_rest_routes();
        DDV_Tenant_Manager::register_rest_routes();
    }

    /**
     * Core ↔ POA wiring.
     *
     * If the POA module plugin is active, it will hook into this action:
     * do_action('ddv_register_poa_extensions');
     *
     * The POA module then injects:
     * - POA folder maps
     * - POA sorting rules
     * - POA compliance requirements
     * - POA governance procedures
     */
    public function wire_poa_module() {
        if ( $this->poa_module_active() ) {
            do_action( 'ddv_register_poa_extensions' );
        }
    }

    /**
     * Detect if POA module is active.
     */
    protected function poa_module_active() {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        return is_plugin_active( 'docdocvault-poa-module/docdocvault-poa-module.php' );
    }
}

/**
 * Create the wp_ddv_tenants and wp_ddv_invitations tables on activation.
 * Registered at file scope (not inside the class constructor) because
 * register_activation_hook() must be called from the main plugin file,
 * before plugins_loaded fires.
 */
register_activation_hook( __FILE__, [ 'DDV_Tenant', 'install' ] );
register_activation_hook( __FILE__, [ 'DDV_Invitation', 'install' ] );
register_deactivation_hook( __FILE__, [ 'DDV_Invitation', 'deactivate' ] );

/**
 * Instantiate the plugin. Without this, DocDocVault_Core's constructor
 * never runs, and none of its add_action() calls — init_modules(),
 * register_rest_routes(), wire_poa_module() — ever register. This line
 * was missing from the file entirely.
 */
new DocDocVault_Core();