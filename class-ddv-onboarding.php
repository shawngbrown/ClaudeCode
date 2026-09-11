<?php
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
class DDV_Onboarding {
 
    /**
     * Initialize hooks.
     */
    public static function init() {
        add_shortcode( 'ddv_onboarding', [ __CLASS__, 'render_onboarding_form' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_complete_onboarding' ] );
    }
 
    /**
     * Enqueue basic CSS/JS for the onboarding form.
     */
    public static function enqueue_assets() {
        wp_register_style(
            'ddv-onboarding-css',
            DDV_CORE_URL . 'assets/css/ddv-onboarding.css',
            [],
            DDV_CORE_VERSION
        );
        wp_enqueue_style( 'ddv-onboarding-css' );
 
        wp_register_script(
            'ddv-onboarding-js',
            DDV_CORE_URL . 'assets/js/ddv-onboarding.js',
            [ 'jquery' ],
            DDV_CORE_VERSION,
            true
        );
        wp_enqueue_script( 'ddv-onboarding-js' );
    }
 
    /**
     * Shortcode renderer: [ddv_onboarding]
     */
    public static function render_onboarding_form() {
        if ( is_user_logged_in() ) {
            return '<p>You are already logged in.</p>';
        }
 
        $step = isset( $_POST['ddv_step'] ) ? intval( $_POST['ddv_step'] ) : 1;
 
        // Handle submission for current step
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ddv_onboarding_submit'] ) ) {
            $step = self::handle_step_submission( $step );
        }
 
        ob_start();
        ?>
        <div class="ddv-onboarding">
            <h2>DocDocVault — New Tenant Onboarding</h2>
            <p>Step <?php echo esc_html( $step ); ?> of 6</p>
            <form method="post">
                <?php wp_nonce_field( 'ddv_onboarding', 'ddv_onboarding_nonce' ); ?>
                <?php self::render_step_fields( $step ); ?>
                <input type="hidden" name="ddv_step" value="<?php echo esc_attr( $step ); ?>" />
                <button type="submit" name="ddv_onboarding_submit">
                    <?php echo $step < 6 ? 'Next' : 'Finish'; ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
 
    /**
     * Render fields for a given step.
     */
    protected static function render_step_fields( $step ) {
        switch ( $step ) {
            case 1:
                // Step 1 — User Information (default fields)
                ?>
                <h3>Your Information</h3>
                <p>Please provide your basic account details.</p>
 
                <label>First Name</label>
                <input type="text" name="ddv_first_name" required />
 
                <label>Last Name</label>
                <input type="text" name="ddv_last_name" required />
 
                <label>Username</label>
                <input type="text" name="ddv_username" required />
                <p style="font-size:0.85em;color:#666;">This is your login name — separate from your email address, and must be unique.</p>
 
                <label>Email</label>
                <input type="email" name="ddv_email" required />
 
                <label>Phone</label>
                <input type="text" name="ddv_phone" required />
 
                <label>Password</label>
                <input type="password" name="ddv_password" required />
 
                <label>Confirm Password</label>
                <input type="password" name="ddv_password_confirm" required />
                <?php
                break;
 
            case 2:
                // Step 2 — Business Information
                ?>
                <h3>Business Information</h3>
                <p>Tell us about your business or association.</p>
 
                <label>Business / Association Name</label>
                <input type="text" name="ddv_business_name" required />
 
                <label>Industry</label>
                <input type="text" name="ddv_industry" required />
 
                <label>State</label>
                <input type="text" name="ddv_state" required />
                <?php
                break;
 
            case 3:
                // Step 3 — Entity Type
                ?>
                <h3>Entity Type</h3>
                <p>Select the legal structure of your organization.</p>
 
                <label>Entity Type</label>
                <select name="ddv_entity_type" required>
                    <option value="">Select...</option>
                    <option value="sole_proprietorship">Sole Proprietorship</option>
                    <option value="partnership">Partnership</option>
                    <option value="lp">Limited Partnership (LP)</option>
                    <option value="llp">Limited Liability Partnership (LLP)</option>
                    <option value="llc">Limited Liability Company (LLC)</option>
                    <option value="corporation">Corporation</option>
                    <option value="nonprofit">Nonprofit</option>
                    <option value="hoa_poa">Property Owners Association (POA / HOA / COA)</option>
                </select>
                <?php
                break;
 
            case 4:
                // Step 4 — Vault Setup (preview)
                ?>
                <h3>Vault Setup</h3>
                <p>We will create a secure vault with folders based on your entity type.</p>
                <p>On completion, DocDocVault will:</p>
                <ul>
                    <li>Create your tenant in the vault</li>
                    <li>Generate entity-specific folder structure</li>
                    <li>Apply permissions and roles</li>
                </ul>
                <p>Click Next to continue.</p>
                <?php
                break;
 
            case 5:
                // Step 5 — Required Documents (preview)
                ?>
                <h3>Required Documents</h3>
                <p>Based on your entity type, DocDocVault will expect certain key documents.</p>
                <p>Examples include:</p>
                <ul>
                    <li>Formation documents (Articles, Certificate, etc.)</li>
                    <li>EIN letter</li>
                    <li>Operating Agreement / Bylaws</li>
                    <li>Licenses and permits</li>
                    <li>Insurance policies</li>
                </ul>
                <p>Click Next to finalize your onboarding.</p>
                <?php
                break;
 
            case 6:
                // Step 6 — Confirmation
                ?>
                <h3>Confirm & Create Account</h3>
                <p>Click Finish to create your DocDocVault account, tenant site, and vault.</p>
                <p>By clicking Finish, you agree that the information provided is accurate.</p>
                <?php
                break;
        }
    }
 
    /**
     * Handle submission for a given step.
     * Returns the next step number.
     */
    protected static function handle_step_submission( $step ) {
        if ( ! isset( $_POST['ddv_onboarding_nonce'] ) || ! wp_verify_nonce( $_POST['ddv_onboarding_nonce'], 'ddv_onboarding' ) ) {
            return $step; // invalid nonce, stay on same step
        }
 
        switch ( $step ) {
            case 1:
                // Validate and store user info in session/transient
                $first_name        = sanitize_text_field( $_POST['ddv_first_name'] ?? '' );
                $last_name         = sanitize_text_field( $_POST['ddv_last_name'] ?? '' );
                $username          = sanitize_user( $_POST['ddv_username'] ?? '', true );
                $email             = sanitize_email( $_POST['ddv_email'] ?? '' );
                $phone             = sanitize_text_field( $_POST['ddv_phone'] ?? '' );
                $password          = $_POST['ddv_password'] ?? '';
                $password_confirm  = $_POST['ddv_password_confirm'] ?? '';
 
                if ( empty( $first_name ) || empty( $last_name ) || empty( $username ) || empty( $email ) || empty( $phone ) || empty( $password ) || empty( $password_confirm ) ) {
                    return 1;
                }
 
                if ( $password !== $password_confirm ) {
                    return 1;
                }
 
                // Username must be genuinely unique — separate check from
                // the email de-dup check in finalize_onboarding(), which
                // exists for a different reason (safe resubmission).
                if ( username_exists( $username ) ) {
                    return 1;
                }
 
                $data = [
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'username'   => $username,
                    'email'      => $email,
                    'phone'      => $phone,
                    // Never persist the raw password. It's encrypted at rest
                    // in the transient and only decrypted once, at the moment
                    // the account is actually created in finalize_onboarding().
                    'password_enc' => self::encrypt_password( $password ),
                ];
                self::store_onboarding_data( 'step1', $data );
 
                return 2;
 
            case 2:
                // Business info
                $business_name = sanitize_text_field( $_POST['ddv_business_name'] ?? '' );
                $industry      = sanitize_text_field( $_POST['ddv_industry'] ?? '' );
                $state         = sanitize_text_field( $_POST['ddv_state'] ?? '' );
 
                if ( empty( $business_name ) || empty( $industry ) || empty( $state ) ) {
                    return 2;
                }
 
                $data = [
                    'business_name' => $business_name,
                    'industry'      => $industry,
                    'state'         => $state,
                ];
                self::store_onboarding_data( 'step2', $data );
 
                return 3;
 
            case 3:
                // Entity type
                $entity_type = sanitize_key( $_POST['ddv_entity_type'] ?? '' );
 
                if ( empty( $entity_type ) ) {
                    return 3;
                }
 
                $data = [
                    'entity_type' => $entity_type,
                ];
                self::store_onboarding_data( 'step3', $data );
 
                return 4;
 
            case 4:
                // Nothing to validate; just proceed
                return 5;
 
            case 5:
                // Nothing to validate; just proceed
                return 6;
 
            case 6:
                // Finalization + redirect now happens earlier, on
                // template_redirect (see maybe_complete_onboarding()),
                // before any output is sent. This case is only reached
                // if that hook already ran and validation failed.
                return 6;
        }
 
        return $step;
    }
 
    /**
     * Encrypt a plaintext password for short-lived transient storage.
     * Not a substitute for hashing at rest long-term — this exists only to
     * avoid holding the raw password in plaintext in the DB for up to an
     * hour while the multi-step wizard is in progress.
     */
    protected static function encrypt_password( $plaintext ) {
        $key   = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv    = random_bytes( 16 );
        $ct    = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $ct );
    }
 
    /**
     * Reverse of encrypt_password().
     */
    protected static function decrypt_password( $encoded ) {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $raw = base64_decode( $encoded );
        $iv  = substr( $raw, 0, 16 );
        $ct  = substr( $raw, 16 );
        return openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
    }
 
    /**
     * Store onboarding data in a transient keyed by session.
     */
    protected static function store_onboarding_data( $step_key, $data ) {
        $session_key = self::get_session_key();
        $existing    = get_transient( $session_key );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        $existing[ $step_key ] = $data;
        set_transient( $session_key, $existing, HOUR_IN_SECONDS );
    }
 
    /**
     * Retrieve all onboarding data.
     */
    protected static function get_onboarding_data() {
        $session_key = self::get_session_key();
        $data        = get_transient( $session_key );
        return is_array( $data ) ? $data : [];
    }
 
    /**
     * Generate a simple session key per visitor.
     */
    protected static function get_session_key() {
        if ( ! isset( $_COOKIE['ddv_onboarding_session'] ) ) {
            $key = 'ddv_onboarding_' . wp_generate_uuid4();
            setcookie( 'ddv_onboarding_session', $key, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            $_COOKIE['ddv_onboarding_session'] = $key;
        }
        return sanitize_text_field( $_COOKIE['ddv_onboarding_session'] );
    }
 
    /**
     * Finalize onboarding: create WP user, tenant site, vault tenant, compliance mapping.
     */
    protected static function finalize_onboarding() {
        $data = self::get_onboarding_data();
 
        $step1 = $data['step1'] ?? [];
        $step2 = $data['step2'] ?? [];
        $step3 = $data['step3'] ?? [];
 
        if ( empty( $step1 ) || empty( $step2 ) || empty( $step3 ) ) {
            return;
        }
 
        // 1. Create WordPress user. Username is now a real, distinct
        // field (see Step 1) — no longer the email address. This is
        // what DDV_Vault_Connector::provision_nextcloud_user() mirrors
        // as the Nextcloud username too, so fixing it here fixes both
        // systems with no changes needed on the vault-connector side.
        $user_id = email_exists( $step1['email'] );
        if ( ! $user_id ) {
            $plain_password = isset( $step1['password_enc'] )
                ? self::decrypt_password( $step1['password_enc'] )
                : ( $step1['password'] ?? '' ); // backward-compat safety net
 
            $username = $step1['username'] ?? $step1['email']; // backward-compat safety net for any in-flight session started before this field existed
 
            $user_id = wp_create_user(
                $username,
                $plain_password,
                $step1['email']
            );
            unset( $plain_password );
            if ( ! is_wp_error( $user_id ) ) {
                wp_update_user(
                    [
                        'ID'           => $user_id,
                        'first_name'   => $step1['first_name'],
                        'last_name'    => $step1['last_name'],
                        'display_name' => $step1['first_name'] . ' ' . $step1['last_name'],
                    ]
                );
            }
        }
 
        // 2. Create the tenant record. This is now a row in wp_ddv_tenants,
        // not scattered user meta — so a second user (invited later via the
        // 4-tier invitation flow) can point at the same tenant instead of
        // each user carrying their own disconnected copy of the business
        // profile.
        if ( ! is_wp_error( $user_id ) ) {
            $existing_tenant_id = get_user_meta( $user_id, 'ddv_tenant_id', true );
 
            if ( empty( $existing_tenant_id ) ) {
                $tenant_id = DDV_Tenant::create_tenant(
                    [
                        'business_name'       => $step2['business_name'],
                        'industry'            => $step2['industry'],
                        'state'               => $step2['state'],
                        'entity_type'         => $step3['entity_type'],
                        'prime_admin_user_id' => $user_id,
                    ]
                );
 
                if ( ! is_wp_error( $tenant_id ) ) {
                    DDV_Tenant::assign_user_to_tenant( $user_id, $tenant_id );
 
                    // The person who completes onboarding is always Prime
                    // Admin for the tenant they just created.
                    $user = new WP_User( $user_id );
                    $user->set_role( DDV_Tenant::ROLE_PRIME_ADMIN );
                }
            }
        }
 
        // 3. Vault provisioning — create the Nextcloud user account,
        // then (with real NC credentials now in hand) build out the
        // tenant's top-level Group Folder and subfolder structure.
        if ( ! is_wp_error( $user_id ) ) {
            DDV_Vault_Connector::provision_nextcloud_user( $user_id );
 
            $vault_tenant_id = get_user_meta( $user_id, 'ddv_tenant_id', true );
            if ( $vault_tenant_id ) {
                DDV_Vault_Connector::provision_tenant_vault( $vault_tenant_id );
            }
        }
 
        // 4. Optionally log the user in and redirect.
        if ( ! is_wp_error( $user_id ) ) {
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id );
        }
 
        // 6. Clear the onboarding transient — nothing (including the
        // encrypted password) needs to persist past account creation.
        delete_transient( self::get_session_key() );
 
        return ! is_wp_error( $user_id );
    }
 
    public static function maybe_complete_onboarding() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }
        if ( ! isset( $_POST['ddv_onboarding_submit'], $_POST['ddv_step'] ) ) {
            return;
        }
        if ( intval( $_POST['ddv_step'] ) !== 6 ) {
            return;
        }
        if ( ! isset( $_POST['ddv_onboarding_nonce'] ) || ! wp_verify_nonce( $_POST['ddv_onboarding_nonce'], 'ddv_onboarding' ) ) {
            return;
        }
        $success = self::finalize_onboarding();
        if ( $success ) {
            wp_redirect( home_url( '/portal' ) );
            exit;
        }
    }
 
    /**
     * REST routes (if you want API-based onboarding later).
     */
    public static function register_rest_routes() {
        register_rest_route(
            'ddv/v1',
            '/onboarding/data',
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'rest_get_onboarding_data' ],
                'permission_callback' => '__return_true',
            ]
        );
    }
 
    public static function rest_get_onboarding_data( WP_REST_Request $request ) {
        $data = self::get_onboarding_data();
 
        // Never expose the encrypted password (or a legacy plaintext one)
        // over the REST response, even though it's tied to the caller's
        // own session cookie.
        if ( isset( $data['step1']['password_enc'] ) ) {
            unset( $data['step1']['password_enc'] );
        }
        if ( isset( $data['step1']['password'] ) ) {
            unset( $data['step1']['password'] );
        }
 
        return new WP_REST_Response(
            [
                'status' => 'ok',
                'data'   => $data,
            ],
            200
        );
    }
}