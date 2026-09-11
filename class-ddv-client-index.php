<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DDV_Client_Index
 *
 * The Master Index page: [ddv_client_index] — a searchable/browsable
 * directory of the current user's own tenant's clients. WP-native, no
 * Nextcloud calls (that only happens on the eventual single-client view,
 * which is a separate, not-yet-built piece — see note in render()).
 *
 * Isolation is NOT reimplemented here — DDV_Client_Workspace's
 * pre_get_posts hook (step 2) already scopes every WP_Query against
 * ddv_client_workspace to the current user's tenant automatically,
 * including secondary queries like the one this shortcode runs. This
 * class only needs to build the query and render results.
 *
 * Filter/browse mechanism, per the architecture doc: tax_query-driven
 * (business type), NOT free-text search. Free-text search against
 * postmeta/PCMF codes is a separate, still-needed piece (WordPress's
 * native search does not index postmeta or taxonomy terms).
 */
class DDV_Client_Index {

    const PER_PAGE = 25;

    public static function init() {
        add_shortcode( 'ddv_client_index', [ __CLASS__, 'render' ] );
    }

    public static function render( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view your clients.</p>';
        }

        $tenant = DDV_Tenant::get_tenant_by_user( get_current_user_id() );
        if ( ! $tenant && ! current_user_can( 'manage_options' ) ) {
            return '<p>No tenant is associated with your account.</p>';
        }

        $paged         = max( 1, intval( $_GET['ddv_paged'] ?? 1 ) );
        $filter_type   = isset( $_GET['ddv_business_type'] ) ? sanitize_key( $_GET['ddv_business_type'] ) : '';

        $query_args = [
            'post_type'      => DDV_Client_Workspace::POST_TYPE,
            'posts_per_page' => self::PER_PAGE,
            'paged'          => $paged,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ( ! empty( $filter_type ) ) {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => DDV_Client_Workspace::TAX_BUSINESS,
                    'field'    => 'slug',
                    'terms'    => $filter_type,
                ],
            ];
        }

        // Tenant scoping is applied automatically here via
        // DDV_Client_Workspace::scope_to_current_tenant() on pre_get_posts
        // — this query does not need to (and should not) add its own
        // tenant_id meta_query clause; that would risk drifting out of
        // sync with the single enforcement point.
        $query = new WP_Query( $query_args );

        $available_types = self::get_available_filter_types();

        ob_start();
        ?>
        <div class="ddv-client-index">
            <?php if ( ! empty( $available_types ) ) : ?>
                <form method="get" class="ddv-client-index-filter">
                    <label for="ddv_business_type">Business Type</label>
                    <select name="ddv_business_type" id="ddv_business_type" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ( $available_types as $slug => $label ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filter_type, $slug ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <?php if ( ! $query->have_posts() ) : ?>
                <p>No clients found<?php echo $filter_type ? ' for this business type' : ''; ?>.</p>
            <?php else : ?>
                <ul class="ddv-client-index-list">
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                        <?php
                        $post_id = get_the_ID();
                        $terms   = wp_get_object_terms( $post_id, DDV_Client_Workspace::TAX_BUSINESS, [ 'fields' => 'names' ] );
                        // The single-client view (where a live Nextcloud
                        // call would render actual folder contents) is not
                        // built yet — build-order step 7 (vault connector)
                        // is a prerequisite. This link target is a forward
                        // reference for that future template to read.
                        $view_url = add_query_arg( 'ddv_client', $post_id );
                        ?>
                        <li class="ddv-client-index-item">
                            <a href="<?php echo esc_url( $view_url ); ?>"><?php the_title(); ?></a>
                            <?php if ( ! empty( $terms ) ) : ?>
                                <span class="ddv-client-index-type"><?php echo esc_html( implode( ', ', $terms ) ); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endwhile; ?>
                </ul>

                <?php if ( $query->max_num_pages > 1 ) : ?>
                    <div class="ddv-client-index-pagination">
                        <?php for ( $i = 1; $i <= $query->max_num_pages; $i++ ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'ddv_paged', $i ) ); ?>"
                               class="<?php echo $i === $paged ? 'current' : ''; ?>">
                                <?php echo esc_html( $i ); ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        wp_reset_postdata();

        return ob_get_clean();
    }

    /**
     * Business-type filter options, derived ONLY from terms actually used
     * by the current user's own tenant's clients — never a global term
     * list. Querying get_terms() directly would leak which business types
     * exist across OTHER tenants (a metadata leak, even without exposing
     * any client names), so this runs its own tenant-scoped query first
     * and aggregates from the results instead.
     */
    protected static function get_available_filter_types() {
        $query = new WP_Query( [
            'post_type'      => DDV_Client_Workspace::POST_TYPE,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        if ( empty( $query->posts ) ) {
            return [];
        }

        $terms = wp_get_object_terms( $query->posts, DDV_Client_Workspace::TAX_BUSINESS, [
            'fields' => 'all',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $options = [];
        foreach ( $terms as $term ) {
            $options[ $term->slug ] = $term->name;
        }

        return $options;
    }
}
