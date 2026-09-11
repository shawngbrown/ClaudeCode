<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ddv_vault_connector
 *
 * Handles provisioning of Nextcloud (cloud.docdocvault.com) user accounts
 * for DDV tenant users. This is Phase 1 (light version): creating a real
 * Nextcloud login tied to each WP user. Folder structure creation and
 * WebDAV file operations are a separate, later phase.
 *
 * Two Nextcloud APIs are in play here, and they are NOT the same thing:
 * - OCS Provisioning API (used in this file) creates/manages Nextcloud
 *   USER ACCOUNTS. Base path: /ocs/v1.php/cloud/...
 * - WebDAV (already confirmed working separately, credentials
 *   admin/DDVcloud2026New!) reads/writes FILES for a user that already
 *   exists. It does not create accounts.
 *
 * Admin credentials used here authenticate as the Nextcloud admin in
 * order to create accounts on behalf of DDV users — those users never
 * see or need the admin password.
 */
class DDV_Vault_Connector {

    // TODO: move these to wp-config.php as defined constants
    // (DDV_NC_BASE_URL, DDV_NC_ADMIN_USER, DDV_NC_ADMIN_PASS) rather than
    // hardcoding here, so credentials aren't sitting in a plugin file.
    const NC_BASE_URL   = 'https://cloud.docdocvault.com';
    const NC_ADMIN_USER = 'admin';
    const NC_ADMIN_PASS = 'DDVcloud2026New!';

    const BRIDGE_URL        = 'https://vault-bridge.docdocvault.com/set-groupfolder-permissions.php';
    const BRIDGE_CREATE_URL = 'https://vault-bridge.docdocvault.com/create-groupfolder.php';
    const BRIDGE_SECRET     = '7ccde52587f522defd05fb6892d26383677bef797b458132b79f0b2505506d78';

    public static function init() {
        add_shortcode( 'ddv_nc_access', [ __CLASS__, 'render_nc_access' ] );
    }

    public static function register_rest_routes() {
        register_rest_route(
            'ddv/v1',
            '/vault/list',
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'list_documents' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function list_documents() {
        return [
            'documents' => []
        ];
    }

    /**
     * Create a Nextcloud user account for this WP user, if one doesn't
     * already exist. Called once at account creation (finalize_onboarding)
     * and again defensively on every login via ensure_nextcloud_access().
     *
     * Returns true on success (including "already provisioned"), false on
     * failure. Never throws — a Nextcloud outage should never block WP
     * account creation or login.
     */
    public static function provision_nextcloud_user( $user_id ) {
        $user_id = intval( $user_id );

        if ( get_user_meta( $user_id, 'ddv_nc_provisioned', true ) ) {
            return true; // already done
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }

        // Nextcloud usernames: keep it simple and stable — same as the
        // WP username (which is the email address in this codebase's
        // onboarding flow). Avoid using anything that could change later
        // (like display name).
        $nc_username = $user->user_login;

        // If the NC account already exists (e.g. created manually, or a
        // prior attempt partially succeeded), don't try to create it
        // again — just record that it's there and stop.
        if ( self::nextcloud_user_exists( $nc_username ) ) {
            update_user_meta( $user_id, 'ddv_nc_username', $nc_username );
            update_user_meta( $user_id, 'ddv_nc_provisioned', current_time( 'mysql' ) );

            $tenant_id = get_user_meta( $user_id, 'ddv_tenant_id', true );
            if ( $tenant_id ) {
                self::add_user_to_tenant_group( $user_id, $nc_username, $tenant_id );
            }

            return true;
        }

        $nc_password = wp_generate_password( 24, true, true );

        $response = wp_remote_post(
            self::NC_BASE_URL . '/ocs/v1.php/cloud/users',
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Authorization'  => 'Basic ' . base64_encode( self::NC_ADMIN_USER . ':' . self::NC_ADMIN_PASS ),
                ],
                'body' => [
                    'userid'   => $nc_username,
                    'password' => $nc_password,
                    'email'    => $user->user_email,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: NC user creation request failed for user ' . $user_id . ':' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ocs_status = $body['ocs']['meta']['statuscode'] ?? null;

        if ( $code !== 200 || $ocs_status !== 100 ) {
            $message = $body['ocs']['meta']['message'] ?? 'unknown error';
            error_log( "DDV_Vault_Connector: NC user creation failed for user {$user_id} (nc_username={$nc_username}): HTTP {$code}, OCS status {$ocs_status}, message: {$message}" );
            return false;
        }

        update_user_meta( $user_id, 'ddv_nc_username', $nc_username );
        update_user_meta( $user_id, 'ddv_nc_password_enc', self::encrypt_nc_password( $nc_password ) );
        update_user_meta( $user_id, 'ddv_nc_provisioned', current_time( 'mysql' ) );

        unset( $nc_password );

        $tenant_id = get_user_meta( $user_id, 'ddv_tenant_id', true );
        if ( $tenant_id ) {
            self::add_user_to_tenant_group( $user_id, $nc_username, $tenant_id );
        }

        return true;
    }

    /**
     * Defensive check called on every login. If provisioning was never
     * completed (or the NC account has since disappeared), retry it.
     */
    public static function ensure_nextcloud_access( $user_id ) {
        $user_id = intval( $user_id );

        if ( ! get_user_meta( $user_id, 'ddv_nc_provisioned', true ) ) {
            self::provision_nextcloud_user( $user_id );
            return;
        }

        $nc_username = get_user_meta( $user_id, 'ddv_nc_username', true );
        if ( $nc_username && ! self::nextcloud_user_exists( $nc_username ) ) {
            delete_user_meta( $user_id, 'ddv_nc_provisioned' );
            self::provision_nextcloud_user( $user_id );
            return;
        }

        // Self-healing: the NC account exists and is marked provisioned,
        // but group assignment may have been skipped (e.g. this user was
        // provisioned before group support existed). Backfill it here
        // rather than leaving a silent, permanent gap.
        if ( $nc_username && ! get_user_meta( $user_id, 'ddv_nc_group', true ) ) {
            $tenant_id = get_user_meta( $user_id, 'ddv_tenant_id', true );
            if ( $tenant_id ) {
                self::add_user_to_tenant_group( $user_id, $nc_username, $tenant_id );
            }
        }
    }

    /**
     * Check whether a Nextcloud user account currently exists.
     */
    protected static function nextcloud_user_exists( $nc_username ) {
        $response = wp_remote_get(
            self::NC_BASE_URL . '/ocs/v1.php/cloud/users/' . rawurlencode( $nc_username ),
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Authorization'  => 'Basic ' . base64_encode( self::NC_ADMIN_USER . ':' . self::NC_ADMIN_PASS ),
                ],
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: NC user existence check failed: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ocs_status = $body['ocs']['meta']['statuscode'] ?? null;

        return $code === 200 && $ocs_status === 100;
    }

    /**
     * Encrypt a Nextcloud password for storage.
     */
    protected static function encrypt_nc_password( $plaintext ) {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv  = random_bytes( 16 );
        $ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $ct );
    }

    /**
     * Reverse of encrypt_nc_password().
     */
    public static function decrypt_nc_password( $encoded ) {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $raw = base64_decode( $encoded );
        $iv  = substr( $raw, 0, 16 );
        $ct  = substr( $raw, 16 );
        return openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
    }

    public static function render_nc_access() {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id         = get_current_user_id();
        $nc_username     = get_user_meta( $user_id, 'ddv_nc_username', true );
        $nc_password_enc = get_user_meta( $user_id, 'ddv_nc_password_enc', true );

        if ( ! $nc_username || ! $nc_password_enc ) {
            return '<p>Your cloud vault access is still being set up. Please check back shortly, or contact support if this persists.</p>';
        }

        $nc_password = self::decrypt_nc_password( $nc_password_enc );

        ob_start();
        ?>
        <div class="ddv-nc-access">
            <h3>Your Document Vault (Nextcloud)</h3>
            <p>Use these credentials to log into your secure cloud vault. You can change your password after logging in.</p>
            <p><strong>Username:</strong> <?php echo esc_html( $nc_username ); ?></p>
            <p>
                <strong>Password:</strong>
                <span id="ddv-nc-pw" style="filter: blur(4px); font-family: monospace;"><?php echo esc_html( $nc_password ); ?></span>
                <button type="button" onclick="var el=document.getElementById('ddv-nc-pw'); el.style.filter = (el.style.filter === 'blur(4px)') ? 'none' : 'blur(4px)';">Show / Hide</button>
            </p>
            <p>
                <a href="<?php echo esc_url( self::NC_BASE_URL ); ?>" target="_blank" rel="noopener" class="ddv-button ddv-primary">Open My Vault</a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function ensure_tenant_group( $tenant_id ) {
        $group_id = 'ddv_tenant_' . intval( $tenant_id );

        if ( self::nextcloud_group_exists( $group_id ) ) {
            return $group_id;
        }

        $response = wp_remote_post(
            self::NC_BASE_URL . '/ocs/v1.php/cloud/groups',
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Authorization'  => 'Basic ' . base64_encode( self::NC_ADMIN_USER . ':' . self::NC_ADMIN_PASS ),
                ],
                'body' => [
                    'groupid' => $group_id,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: NC group creation request failed for tenant ' . $tenant_id. ': ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ocs_status = $body['ocs']['meta']['statuscode'] ?? null;

        if ( $code !== 200 || $ocs_status !== 100 ) {
            $message = $body['ocs']['meta']['message'] ?? 'unknown error';
            error_log( "DDV_Vault_Connector: NC group creation failed for tenant {$tenant_id} (group_id={$group_id}): HTTP {$code}, OCS status {$ocs_status}, message: {$message}" );
            return false;
        }

        return $group_id;
    }

    /**
     * Set a Group Folder's base permissions for a given group, via the
     * VPS-side bridge script. This exists because Nextcloud's OCS API
     * has no permission-setting endpoint, and the occ CLI has a
     * confirmed bug with multi-word permission input
     * (nextcloud/groupfolders#706). The bridge writes directly to
     * oc_group_folders_groups, which is what both of those broken paths
     * would ultimately have done anyway. This same call also handles
     * first-time group-to-folder assignment (INSERT ... ON DUPLICATE
     * KEY UPDATE on the bridge side) — no separate assignment step or
     * `occ groupfolders:group` call is needed.
     *
     * $permissions is the raw bitmask: Read=1, Update=2, Create=4,
     * Delete=8, Share=16. Common values: 1 = read only, 15 = read+write
     * +delete (DDV default working set), 31 = everything including share.
     */
    public static function set_group_folder_permissions( $folder_id, $group_id, $permissions ) {
        $response = wp_remote_post(
            self::BRIDGE_URL,
            [
                'headers' => [
                    'Content-Type'          => 'application/json',
                    'X-DDV-Bridge-Secret'   => self::BRIDGE_SECRET,
                ],
                'body'    => wp_json_encode( [
                    'folder_id'   => intval( $folder_id ),
                    'group_id'    => $group_id,
                    'permissions' => intval( $permissions ),
                ] ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: bridge request failed: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) ) {
            $message = $body['message'] ?? 'unknown error';
            error_log( "DDV_Vault_Connector: bridge reported failure for folder_id={$folder_id}, group_id={$group_id}: {$message}" );
            return false;
        }

        return true;
    }

    /**
     * Create a new Group Folder via the VPS-side bridge script (which
     * runs `occ groupfolders:create` through a tightly-scoped Docker
     * exec call — raw SQL insertion isn't safe here because
     * oc_group_folders ties into Nextcloud's internal filecache/storage
     * bookkeeping). Returns the new folder_id on success, false on
     * failure.
     */
    protected static function create_group_folder( $folder_name ) {
        $response = wp_remote_post(
            self::BRIDGE_CREATE_URL,
            [
                'headers' => [
                    'Content-Type'        => 'application/json',
                    'X-DDV-Bridge-Secret' => self::BRIDGE_SECRET,
                ],
                'body'    => wp_json_encode( [ 'folder_name' => $folder_name ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: group folder creation request failed: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) || empty( $body['folder_id'] ) ) {
            $message = $body['message'] ?? 'unknown error';
            error_log( "DDV_Vault_Connector: group folder creation failed for '{$folder_name}': {$message}" );
            return false;
        }

        return intval( $body['folder_id'] );
    }

    /**
     * Provision a tenant's entire top-level vault: create the Group
     * Folder, assign the tenant's NC group with the standard working
     * permission set, then create the fixed Templates / Business
     * Resources / Clients subfolder structure via WebDAV using the
     * Prime Admin's own credentials (they're guaranteed to be a member
     * of the tenant's group, unlike the NC admin account, which cannot
     * see a Group Folder it isn't a member of).
     *
     * Called once, right after a tenant is created (finalize_onboarding).
     * Safe to call again for an existing tenant — folder creation and
     * permission assignment are both idempotent by design; WebDAV
     * MKCOL on an already-existing folder returns 405, treated here as
     * a non-fatal "already there" case, not an error.
     */
    public static function provision_tenant_vault( $tenant_id ) {
        $tenant = DDV_Tenant::get_tenant( $tenant_id );
        if ( ! $tenant ) {
            error_log( "DDV_Vault_Connector: provision_tenant_vault called with unknown tenant_id {$tenant_id}" );
            return false;
        }

        // Reuse the existing folder if this tenant was already
        // provisioned (e.g. this got called a second time).
        $folder_id = intval( $tenant['nc_folder_id'] ?? 0 );

        if ( ! $folder_id ) {
            $folder_name = self::build_tenant_folder_name( $tenant_id, $tenant['business_name'] );
            $folder_id   = self::create_group_folder( $folder_name );

            if ( ! $folder_id ) {
                return false;
            }

            DDV_Tenant::set_nc_folder_id( $tenant_id, $folder_id );
        }

        $group_id = 'ddv_tenant_' . intval( $tenant_id );

        // 15 = Read + Write + Delete (no Share) — the standard DDV
        // working set, matching the manually-verified default.
        $assigned = self::set_group_folder_permissions( $folder_id, $group_id, 15 );

        if ( ! $assigned ) {
            error_log( "DDV_Vault_Connector: tenant vault group assignment failed for tenant {$tenant_id}, folder {$folder_id}" );
            return false;
        }

        // Subfolders via WebDAV, using the Prime Admin's own NC
        // credentials — the NC admin account is NOT a member of the
        // tenant's group and cannot see this Group Folder at all.
        $prime_admin_id  = intval( $tenant['prime_admin_user_id'] );
        $nc_username     = get_user_meta( $prime_admin_id, 'ddv_nc_username', true );
        $nc_password_enc = get_user_meta( $prime_admin_id, 'ddv_nc_password_enc', true );

        if ( ! $nc_username || ! $nc_password_enc ) {
            error_log( "DDV_Vault_Connector: cannot create tenant subfolders for tenant {$tenant_id} — Prime Admin (user {$prime_admin_id}) has no NC credentials yet." );
            return true; // folder + permissions still succeeded; subfolders can be retried later
        }

        $nc_password = self::decrypt_nc_password( $nc_password_enc );
        $folder_name = self::build_tenant_folder_name( $tenant_id, $tenant['business_name'] );

        foreach ( [ 'Templates', 'Business Resources', 'Clients' ] as $subfolder ) {
            self::webdav_create_folder( $nc_username, $nc_password, $folder_name . '/' . $subfolder );
        }

        return true;
    }

    /**
     * Create one client's PCMF-coded subfolder inside a tenant's
     * Clients/ directory. Called from DDV_Client_Onboarding once a new
     * client's Client Workspace post (and its pcmf_code) exist.
     */
    public static function provision_client_folder( $tenant_id, $client_post_id ) {
        $tenant = DDV_Tenant::get_tenant( $tenant_id );
        if ( ! $tenant ) {
            return false;
        }

        $prime_admin_id  = intval( $tenant['prime_admin_user_id'] );
        $nc_username     = get_user_meta( $prime_admin_id, 'ddv_nc_username', true );
        $nc_password_enc = get_user_meta( $prime_admin_id, 'ddv_nc_password_enc', true );

        if ( ! $nc_username || ! $nc_password_enc ) {
            error_log( "DDV_Vault_Connector: cannot create client folder — tenant {$tenant_id} Prime Admin has no NC credentials." );
            return false;
        }

        $pcmf_code   = get_post_meta( $client_post_id, 'pcmf_code', true );
        $client_name = get_the_title( $client_post_id );

        if ( ! $pcmf_code ) {
            error_log( "DDV_Vault_Connector: client post {$client_post_id} has no pcmf_code yet, cannot name its folder." );
            return false;
        }

        $safe_client_name = preg_replace( '/[^a-zA-Z0-9]+/', '', $client_name );
        $subfolder_name   = $pcmf_code . '_' . $safe_client_name;

        $nc_password = self::decrypt_nc_password( $nc_password_enc );
        $folder_name = self::build_tenant_folder_name( $tenant_id, $tenant['business_name'] );

        return self::webdav_create_folder( $nc_username, $nc_password, $folder_name . '/Clients/' . $subfolder_name );
    }

    /**
     * Build the tenant's top-level folder name: Tenant_{id}_{Slug}.
     * Deliberately matches the naming already used for
     * Tenant_5_JoesNursingHome, and stays within
     * create-groupfolder.php's strict [a-zA-Z0-9_-] validation.
     */
    protected static function build_tenant_folder_name( $tenant_id, $business_name ) {
        $slug = preg_replace( '/[^a-zA-Z0-9]+/', '', $business_name );
        return 'Tenant_' . intval( $tenant_id ) . '_' . $slug;
    }

    /**
     * Create a folder via WebDAV MKCOL, using a specific NC user's own
     * credentials (required for Group Folders — the NC admin account
     * cannot see a Group Folder it isn't a member of). Treats 201
     * (created) and 405 (already exists) both as success.
     */
    protected static function webdav_create_folder( $nc_username, $nc_password, $relative_path ) {
        $encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) );
        $url = self::NC_BASE_URL . '/remote.php/dav/files/' . rawurlencode( $nc_username ) . '/' . $encoded_path;

        $response = wp_remote_request(
            $url,
            [
                'method'  => 'MKCOL',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $nc_username . ':' . $nc_password ),
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: WebDAV MKCOL failed for ' . $relative_path . ': ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 201 && $code !== 405 ) {
            error_log( "DDV_Vault_Connector: WebDAV MKCOL for {$relative_path} returned unexpected status {$code}" );
            return false;
        }

        return true;
    }

    /**
     * Check a real file's current state in Nextcloud via WebDAV
     * PROPFIND (Depth: 0 — this one resource only, not its children).
     * Built for DDV_Reconciliation, which needs to distinguish three
     * genuinely different outcomes, not just true/false:
     *
     *   - Returns a string (the file's real last-modified timestamp,
     *     exactly as Nextcloud reports it) if the file exists.
     *   - Returns null if the file genuinely does not exist (a real
     *     404 — the pointer is broken, not a technical failure).
     *   - Returns false on an actual technical failure (connection
     *     error, unexpected status code) — different from "missing":
     *     reconciliation should retry later, not flag broken.
     *
     * Uses a specific NC user's own credentials, same reasoning as
     * webdav_create_folder() — the NC admin account cannot see a
     * Group Folder it isn't a member of.
     */
    public static function webdav_check_file( $nc_username, $nc_password, $relative_path ) {
        $encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) );
        $url = self::NC_BASE_URL . '/remote.php/dav/files/' . rawurlencode( $nc_username ) . '/' . $encoded_path;

        $response = wp_remote_request(
            $url,
            [
                'method'  => 'PROPFIND',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $nc_username . ':' . $nc_password ),
                    'Depth'         => '0',
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: WebDAV PROPFIND failed for ' . $relative_path . ': ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 404 ) {
            return null; // genuinely missing — a real reconciliation finding, not an error
        }

        if ( $code !== 207 ) {
            error_log( "DDV_Vault_Connector: WebDAV PROPFIND for {$relative_path} returned unexpected status {$code}" );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( preg_match( '#<d:getlastmodified>(.*?)</d:getlastmodified>#', $body, $matches ) ) {
            return $matches[1];
        }

        // 207 but no last-modified found — treat as a technical
        // parsing failure, not a confirmed "missing" finding.
        error_log( "DDV_Vault_Connector: WebDAV PROPFIND for {$relative_path} returned 207 but no getlastmodified found in body." );
        return false;
    }

    /**
     * Provision the full stage-folder cascade for a new enforcement
     * case: Clients/{PCMF}_ClientName/Legal_Enforcement/{case}/ with
     * all four gate folders (Complaint Intake, Notice Sent, Hearing
     * Pending, Closed) created up front. Called from
     * DDV_Enforcement_Case::open_case().
     */
    /**
     * Create a folder path one level at a time, so an intermediate
     * folder that doesn't exist yet (e.g. "Legal Enforcement" before
     * any case in that category has ever been opened) gets created
     * along the way instead of causing a 409 Conflict. This is the fix
     * for the bug found 2026-08-08: WebDAV MKCOL can only create ONE
     * new path segment at a time — it cannot create
     * "Legal Enforcement/Fines/CaseFolder" in one call if
     * "Legal Enforcement" doesn't already exist.
     *
     * $relative_path is the full path from the user's WebDAV root,
     * e.g. "Tenant_6_.../Clients/PCMF.../Legal Enforcement/Fines/428_Case".
     * Returns true only if every segment along the way succeeded
     * (created or already existed).
     */
    public static function ensure_folder_path_exists( $nc_username, $nc_password, $relative_path ) {
        $segments = explode( '/', trim( $relative_path, '/' ) );
        $built_so_far = '';
        $all_succeeded = true;

        foreach ( $segments as $segment ) {
            $built_so_far = $built_so_far === '' ? $segment : $built_so_far . '/' . $segment;
            $succeeded = self::webdav_create_folder( $nc_username, $nc_password, $built_so_far );
            $all_succeeded = $all_succeeded && $succeeded;
        }

        return $all_succeeded;
    }

    /**
     * snake_case compliance-category key -> readable folder name, e.g.
     * "statutory_due_process" -> "Statutory Due Process". Deliberately
     * duplicated here rather than depending on DDV_Checklist_Renderer's
     * protected humanize() method, to keep these two classes decoupled.
     */
    protected static function humanize_category_key( $key ) {
        return ucwords( str_replace( '_', ' ', $key ) );
    }

    /**
     * Provision the full stage-folder cascade for a new enforcement
     * case: {Tenant}/Clients/{PCMF}_{ClientName}/Legal Enforcement/{Sub
     * Category}/{case}/ with all four gate folders created inside it.
     *
     * $case_category MUST be one of the real Legal Enforcement
     * sub-category keys from the compliance data (assessment, fines,
     * fees, towing, attorney, statutory_due_process, owner_rights) —
     * validated by DDV_Enforcement_Case before this is ever called, but
     * checked again here defensively since this method writes real
     * folders.
     *
     * Uses ensure_folder_path_exists() rather than a single MKCOL call,
     * fixing the 2026-08-08 bug where "Legal Enforcement/Fines" failed
     * with 409 Conflict on a client's first-ever case in that category
     * (WebDAV MKCOL can only create one new path level at a time).
     *
     * This is deliberately still "eager" at the per-case level (all
     * four stage folders are created when the case opens, not lazily
     * per document) — the laziness that matters is at the CATEGORY
     * level: only the one sub-category folder an actual case needs
     * ever gets created, not all 7 Legal Enforcement sub-categories or
     * the other 11 top-level compliance categories for a client that
     * may never need them.
     */
    public static function provision_case_folders( $tenant_id, $client_post_id, $case_post_id, $case_title, $case_category ) {
        $case_category = sanitize_key( $case_category );

        $valid_categories = [ 'assessment', 'fines', 'fees', 'towing', 'attorney', 'statutory_due_process', 'owner_rights' ];
        if ( ! in_array( $case_category, $valid_categories, true ) ) {
            error_log( "DDV_Vault_Connector: '{$case_category}' is not a valid Legal Enforcement sub-category, cannot build case folder." );
            return false;
        }

        $tenant = DDV_Tenant::get_tenant( $tenant_id );
        if ( ! $tenant ) {
            return false;
        }

        $prime_admin_id  = intval( $tenant['prime_admin_user_id'] );
        $nc_username     = get_user_meta( $prime_admin_id, 'ddv_nc_username', true );
        $nc_password_enc = get_user_meta( $prime_admin_id, 'ddv_nc_password_enc', true );

        if ( ! $nc_username || ! $nc_password_enc ) {
            error_log( "DDV_Vault_Connector: cannot create case folders — tenant {$tenant_id} Prime Admin has no NC credentials." );
            return false;
        }

        $pcmf_code   = get_post_meta( $client_post_id, 'pcmf_code', true );
        $client_name = get_the_title( $client_post_id );

        if ( ! $pcmf_code ) {
            error_log( "DDV_Vault_Connector: client post {$client_post_id} has no pcmf_code yet, cannot build case folder path." );
            return false;
        }

        $safe_client_name = preg_replace( '/[^a-zA-Z0-9]+/', '', $client_name );
        $client_folder     = $pcmf_code . '_' . $safe_client_name;
        $case_folder       = DDV_Enforcement_Case::build_case_folder_name( $case_post_id, $case_title );
        $category_folder   = self::humanize_category_key( $case_category );

        $nc_password = self::decrypt_nc_password( $nc_password_enc );
        $tenant_folder_name = self::build_tenant_folder_name( $tenant_id, $tenant['business_name'] );

        $base_path = $tenant_folder_name . '/Clients/' . $client_folder . '/Legal Enforcement/' . $category_folder . '/' . $case_folder;

        // Build the whole path one segment at a time — this is the
        // actual fix. "Legal Enforcement" and the sub-category folder
        // may not exist yet (first case of this type for this client),
        // and a single MKCOL call cannot create multiple new levels.
        $path_ok = self::ensure_folder_path_exists( $nc_username, $nc_password, $base_path );

        if ( ! $path_ok ) {
            error_log( "DDV_Vault_Connector: failed to build base case folder path {$base_path}" );
            return false;
        }

        $all_succeeded = true;
        foreach ( DDV_Enforcement_Case::STAGE_FOLDERS as $stage_folder_name ) {
            $succeeded = self::webdav_create_folder( $nc_username, $nc_password, $base_path . '/' . $stage_folder_name );
            $all_succeeded = $all_succeeded && $succeeded;
        }

        return $all_succeeded;
    }

    protected static function nextcloud_group_exists( $group_id ) {
        $response = wp_remote_get(
            self::NC_BASE_URL . '/ocs/v1.php/cloud/groups/' . rawurlencode( $group_id ),
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Authorization'  => 'Basic ' . base64_encode( self::NC_ADMIN_USER . ':' . self::NC_ADMIN_PASS ),
                ],
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: NC group existence check failed: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ocs_status = $body['ocs']['meta']['statuscode'] ?? null;

        return $code === 200 && $ocs_status === 100;
    }

    public static function add_user_to_tenant_group( $user_id, $nc_username, $tenant_id ) {
        $group_id = self::ensure_tenant_group( $tenant_id );

        if ( ! $group_id ) {
            return false;
        }

        $response = wp_remote_post(
            self::NC_BASE_URL . '/ocs/v1.php/cloud/users/' . rawurlencode( $nc_username ) . '/groups',
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Authorization'  => 'Basic ' . base64_encode( self::NC_ADMIN_USER . ':' . self::NC_ADMIN_PASS ),
                ],
                'body' => [
                    'groupid' => $group_id,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'DDV_Vault_Connector: adding user to NC group failed for user ' . $user_id . ': ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ocs_status = $body['ocs']['meta']['statuscode'] ?? null;

        if ( $code !== 200 || ( $ocs_status !== 100 && $ocs_status !== 102 ) ) {
            $message = $body['ocs']['meta']['message'] ?? 'unknown error';
            error_log( "DDV_Vault_Connector: adding user {$user_id} to NC group {$group_id} failed: HTTP {$code}, OCS status {$ocs_status}, message: {$message}" );
            return false;
        }

        update_user_meta( $user_id, 'ddv_nc_group', $group_id );

        return true;
    }
}