<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Sorting_Engine {

    /**
     * Initialize hooks.
     */
    public static function init() {
        // Hook for future integration (e.g., when files are uploaded via vault connector)
    }

    /**
     * Register REST routes.
     */
    public static function register_rest_routes() {
        register_rest_route(
            'ddv/v1',
            '/sorting/classify',
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'classify_document' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Main classification endpoint.
     *
     * Expects:
     * - filename
     * - entity_type (llc, corporation, nonprofit, lp, llp, partnership, sole_proprietorship, hoa_poa)
     * - optional: keywords, content_snippet
     */
    public static function classify_document( WP_REST_Request $request ) {
        $filename      = sanitize_text_field( $request->get_param( 'filename' ) );
        $entity_type   = sanitize_key( $request->get_param( 'entity_type' ) );
        $keywords      = $request->get_param( 'keywords' );
        $content_snip  = $request->get_param( 'content_snippet' );

        if ( empty( $filename ) || empty( $entity_type ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'filename and entity_type are required.',
                ],
                400
            );
        }

        $keywords = is_array( $keywords ) ? array_map( 'sanitize_text_field', $keywords ) : [];
        $content  = is_string( $content_snip ) ? strtolower( $content_snip ) : '';

        // Determine base folder map
        $folder_map = self::get_base_folder_map( $entity_type );

        // Determine classification category
        $category = self::detect_category( $filename, $keywords, $content, $entity_type );

        // Resolve folder path
        $folder = isset( $folder_map[ $category ] ) ? $folder_map[ $category ] : $folder_map['default'];

        // Generate auto-name
        $auto_name = self::generate_auto_name( $filename, $entity_type, $category );

        return new WP_REST_Response(
            [
                'status'      => 'ok',
                'entity_type' => $entity_type,
                'category'    => $category,
                'folder'      => $folder,
                'auto_name'   => $auto_name,
            ],
            200
        );
    }

    /**
     * Base folder maps per entity type. hoa_poa is a first-class entity
     * type, not a modifier on 'nonprofit'.
     */
    protected static function get_base_folder_map( $entity_type ) {
        $entity_type = strtolower( $entity_type );

        if ( $entity_type === 'hoa_poa' ) {
            return [
                'ccr'              => '/Governing Documents/CCRs/',
                'bylaws'           => '/Governing Documents/Bylaws/',
                'rules'            => '/Governing Documents/Rules & Regulations/',
                'architectural'    => '/Governing Documents/Architectural Guidelines/',
                'amendment'        => '/Governing Documents/Amendments/',
                'procedural_notice'=> '/Governing Documents/Procedural Records/Notices Sent/',
                'procedural_ballot'=> '/Governing Documents/Procedural Records/Ballots & Voting Records/',
                'procedural_adopt' => '/Governing Documents/Procedural Records/Adoption Procedures/',
                'minutes'          => '/Board/Meeting Minutes/',
                'legal'            => '/Legal/',
                'legal_lien'       => '/Legal/Liens & Enforcement/',
                'legal_litigation' => '/Legal/Litigation/',
                'vendor_contract'  => '/Contracts & Vendors/',
                'vendor_landscape' => '/Contracts & Vendors/Landscaping/',
                'vendor_maintenance'=> '/Contracts & Vendors/Maintenance/',
                'vendor_security'  => '/Contracts & Vendors/Security/',
                'vendor_trash'     => '/Contracts & Vendors/Trash & Utilities/',
                'vendor_insurance' => '/Contracts & Vendors/Insurance/',
                'vendor_mgmt'      => '/Contracts & Vendors/Management Company/',
                'default'          => '/Compliance/',
            ];
        }

        switch ( $entity_type ) {
            case 'llc':
                return [
                    'formation'   => '/Formation/',
                    'compliance'  => '/Compliance/',
                    'financial'   => '/Financial/',
                    'licenses'    => '/Licenses/',
                    'insurance'   => '/Insurance/',
                    'operations'  => '/Operations/',
                    'minutes'     => '/Meeting Minutes/',
                    'correspondence' => '/Correspondence/',
                    'default'     => '/Compliance/',
                ];

            case 'corporation':
                return [
                    'board_minutes'      => '/Board/Meeting Minutes/',
                    'shareholder_minutes'=> '/Shareholders/Meeting Minutes/',
                    'compliance'         => '/Compliance/',
                    'financial'          => '/Financial/',
                    'legal'              => '/Legal/',
                    'hr'                 => '/HR/',
                    'operations'         => '/Operations/',
                    'default'            => '/Compliance/',
                ];

            case 'nonprofit':
                return [
                    'irs'         => '/IRS/',
                    'board_minutes'=> '/Board/Meeting Minutes/',
                    'programs'    => '/Programs/',
                    'compliance'  => '/Compliance/',
                    'financial'   => '/Financial/',
                    'grants'      => '/Grants/',
                    'default'     => '/Compliance/',
                ];

            case 'lp':
                return [
                    'investors'   => '/Investors/',
                    'assets'      => '/Assets/',
                    'compliance'  => '/Compliance/',
                    'financial'   => '/Financial/',
                    'legal'       => '/Legal/',
                    'minutes'     => '/Meeting Minutes/',
                    'default'     => '/Compliance/',
                ];

            case 'llp':
            case 'partnership':
                return [
                    'formation'   => '/Formation/',
                    'compliance'  => '/Compliance/',
                    'financial'   => '/Financial/',
                    'operations'  => '/Operations/',
                    'minutes'     => '/Meeting Minutes/',
                    'default'     => '/Compliance/',
                ];

            case 'sole_proprietorship':
            default:
                return [
                    'financial'   => '/Financial/',
                    'licenses'    => '/Licenses/',
                    'insurance'   => '/Insurance/',
                    'operations'  => '/Operations/',
                    'default'     => '/Compliance/',
                ];
        }
    }

    /**
     * Detect category based on filename, keywords, and content.
     */
    protected static function detect_category( $filename, $keywords, $content, $entity_type ) {
        $name = strtolower( $filename );
        $kw   = implode( ' ', $keywords );
        $kw   = strtolower( $kw );

        // hoa_poa-specific detection
        if ( strtolower( $entity_type ) === 'hoa_poa' ) {
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'cc&r', 'covenants', 'restrictions', 'declaration' ] ) ) {
                return 'ccr';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'bylaw', 'by-laws' ] ) ) {
                return 'bylaws';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'rule', 'regulation', 'policy' ] ) ) {
                return 'rules';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'architectural', 'acc guidelines', 'design standards' ] ) ) {
                return 'architectural';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'amendment', 'amended', 'change to ccr', 'change to bylaws' ] ) ) {
                return 'amendment';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'notice of meeting', 'notice of rule change', 'notice to owners' ] ) ) {
                return 'procedural_notice';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'ballot', 'vote record', 'voting record' ] ) ) {
                return 'procedural_ballot';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'adoption procedure', 'adopted by board', 'adopted by owners' ] ) ) {
                return 'procedural_adopt';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'minutes', 'board meeting', 'annual meeting', 'special meeting' ] ) ) {
                return 'minutes';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'lien', 'foreclosure', 'demand letter', 'attorney', 'litigation', 'lawsuit' ] ) ) {
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'lien', 'foreclosure' ] ) ) {
                    return 'legal_lien';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'litigation', 'lawsuit', 'complaint' ] ) ) {
                    return 'legal_litigation';
                }
                return 'legal';
            }
            if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'contract', 'agreement', 'vendor', 'service provider' ] ) ) {
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'landscap' ] ) ) {
                    return 'vendor_landscape';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'maintenance', 'repair' ] ) ) {
                    return 'vendor_maintenance';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'security', 'patrol' ] ) ) {
                    return 'vendor_security';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'trash', 'garbage', 'waste', 'utility' ] ) ) {
                    return 'vendor_trash';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'insurance', 'policy', 'carrier' ] ) ) {
                    return 'vendor_insurance';
                }
                if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'management company', 'hoa management', 'association management' ] ) ) {
                    return 'vendor_mgmt';
                }
                return 'vendor_contract';
            }
        }

        // Generic detection (non-POA)
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'minutes', 'board meeting', 'shareholder meeting', 'annual meeting', 'special meeting' ] ) ) {
            return 'minutes';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'articles of organization', 'certificate of formation', 'articles of incorporation', 'formation' ] ) ) {
            return 'formation';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'license', 'permit', 'registration' ] ) ) {
            return 'licenses';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'insurance', 'policy', 'coverage' ] ) ) {
            return 'insurance';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'financial', 'statement', 'balance sheet', 'income statement', 'tax return' ] ) ) {
            return 'financial';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'compliance', 'annual report', 'franchise tax', 'state filing' ] ) ) {
            return 'compliance';
        }
        if ( self::contains_any( $name . ' ' . $kw . ' ' . $content, [ 'contract', 'agreement', 'vendor', 'service provider' ] ) ) {
            return 'operations';
        }

        return 'default';
    }

    /**
     * Helper: does haystack contain any of the needles?
     */
    protected static function contains_any( $haystack, $needles ) {
        foreach ( $needles as $needle ) {
            if ( strpos( $haystack, strtolower( $needle ) ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate auto-name based on entity type and category.
     */
    protected static function generate_auto_name( $filename, $entity_type, $category ) {
        $entity_type = strtolower( $entity_type );
        $base        = pathinfo( $filename, PATHINFO_FILENAME );
        $date        = current_time( 'Ymd' );

        // Normalize entity prefix
        switch ( $entity_type ) {
            case 'hoa_poa':
                $prefix = 'POA';
                break;
            case 'llc':
                $prefix = 'LLC';
                break;
            case 'corporation':
                $prefix = 'CORP';
                break;
            case 'nonprofit':
                $prefix = 'NONPROFIT';
                break;
            case 'lp':
                $prefix = 'LP';
                break;
            case 'llp':
                $prefix = 'LLP';
                break;
            case 'partnership':
                $prefix = 'PARTNERSHIP';
                break;
            case 'sole_proprietorship':
            default:
                $prefix = 'BUS';
                break;
        }

        // Category label
        $cat_label = strtoupper( str_replace( ' ', '', $category ) );

        // Auto-name pattern: PREFIX-Category-Date-BaseName
        return sprintf(
            '%s-%s-%s-%s',
            $prefix,
            $cat_label,
            $date,
            self::slugify( $base )
        );
    }

    /**
     * Slugify a string for filenames.
     */
    protected static function slugify( $text ) {
        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9]+/', '-', $text );
        $text = trim( $text, '-' );
        return $text ?: 'document';
    }
}
