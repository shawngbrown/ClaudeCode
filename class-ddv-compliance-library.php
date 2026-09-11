<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DDV_Compliance_Library {
    
/**
 * Load the JSON data file for a single entity type, optionally merged
 * with an industry-specific layer (Addendum 12).
 *
 * Entity-type files remain the generic formation baseline, unchanged:
 * data/{entity}.json (e.g. data/hoa_poa.json). Industry files are a
 * SEPARATE, independent layer — data/industry_{industry}.json — added
 * on top when $industry is provided and a matching file exists. This
 * is deliberately additive at the data layer only: entity categories
 * stay exactly as they are, industry categories get merged in
 * alongside them. Nothing downstream (DDV_Checklist_Renderer, etc.)
 * needs to change — they already just consume whatever this method
 * returns.
 *
 * $industry is expected to already be a normalized/controlled value
 * (see Addendum 12 — free-text industry input can't reliably match a
 * filename; that's a separate, not-yet-resolved product decision).
 * Passing null (the default) preserves every existing call site's
 * behavior unchanged.
 *
 * Accepts either shape for both entity and industry files:
 *   { "entities": { "hoa_poa": { "categories": {...} } } }
 *   { "industries": { "cpa_firm": { "categories": {...} } } }
 *   { "categories": {...} }
 * so existing files exported with the old wrapper don't need re-editing.
 */
public static function get_compliance_data( $entity, $industry = null ) {
    $entity = sanitize_key( $entity );
    $file   = DDV_CORE_PATH . 'data/' . $entity . '.json';

    if ( ! file_exists( $file ) ) {
        return [];
    }

    $json = file_get_contents( $file );
    $data = json_decode( $json, true );

    if ( ! is_array( $data ) ) {
        return [];
    }

    if ( isset( $data['entities'][ $entity ] ) ) {
        $entity_data = $data['entities'][ $entity ];
    } elseif ( isset( $data['categories'] ) ) {
        $entity_data = $data;
    } else {
        $entity_data = [];
    }

    if ( empty( $industry ) ) {
        return $entity_data;
    }

    $industry      = sanitize_key( $industry );
    $industry_file = DDV_CORE_PATH . 'data/industry_' . $industry . '.json';

    if ( ! file_exists( $industry_file ) ) {
        // No matching industry layer — not an error, just nothing to
        // merge. Return the entity baseline exactly as before.
        return $entity_data;
    }

    $industry_json = file_get_contents( $industry_file );
    $industry_raw  = json_decode( $industry_json, true );

    if ( ! is_array( $industry_raw ) ) {
        return $entity_data;
    }

    if ( isset( $industry_raw['industries'][ $industry ] ) ) {
        $industry_data = $industry_raw['industries'][ $industry ];
    } elseif ( isset( $industry_raw['categories'] ) ) {
        $industry_data = $industry_raw;
    } else {
        $industry_data = [];
    }

    $merged = $entity_data;

    if ( ! empty( $industry_data['categories'] ) && is_array( $industry_data['categories'] ) ) {
        $merged['categories'] = array_merge(
            $merged['categories'] ?? [],
            $industry_data['categories']
        );
    }

    if ( ! empty( $industry_data['document_citations'] ) && is_array( $industry_data['document_citations'] ) ) {
        $merged['document_citations'] = array_merge(
            $merged['document_citations'] ?? [],
            $industry_data['document_citations']
        );
    }

    return $merged;
}

public static function get_checklist( $entity, $industry = null ) {
    $entity = sanitize_key( $entity );
    $data   = self::get_compliance_data( $entity, $industry );

    if ( empty( $data['categories'] ) ) {
        return null;
    }

    return $data;
}


    public static function init() {
        // Future: load rules from options or JSON.
    }

    public static function register_rest_routes() {
        register_rest_route(
            'ddv/v1',
            '/compliance/requirements',
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'get_requirements' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Dashboard compliance summary
     */
    public function get_summary() {
        return [
            'completed'     => 0,
            'pending'       => 0,
            'next_deadline' => 'None Scheduled'
        ];
    }

/**
     * Return compliance requirements based on entity_type.
     */
    public static function get_requirements( WP_REST_Request $request ) {
        $entity_type = sanitize_key( $request->get_param( 'entity_type' ) );

        if ( $entity_type === 'hoa_poa' ) {
            return new WP_REST_Response(
                [
                    'status'       => 'ok',
                    'entity_type'  => 'hoa_poa',
                    'requirements' => self::get_poa_requirements(),
                ],
                200
            );
        }

        $requirements = self::get_generic_requirements( $entity_type );

        return new WP_REST_Response(
            [
                'status'       => 'ok',
                'entity_type'  => $entity_type,
                'requirements' => $requirements,
            ],
            200
        );
    }

    /**
     * Generic entity-type compliance requirements.
     */
    protected static function get_generic_requirements( $entity_type ) {
        switch ( $entity_type ) {
            case 'llc':
                return [
                    'Formation' => [
                        'Certificate of Formation / Articles of Organization',
                        'Operating Agreement',
                        'Initial Resolution(s)',
                    ],
                    'Tax & Filings' => [
                        'EIN Confirmation Letter',
                        'Franchise Tax Filings (if applicable)',
                        'Annual Reports / Public Information Reports',
                    ],
                    'Licenses & Permits' => [
                        'State Licenses',
                        'Local Permits',
                        'Professional Licenses (if applicable)',
                    ],
                    'Insurance' => [
                        'General Liability Policy',
                        'Professional Liability / E&O (if applicable)',
                        'Workers’ Compensation (if applicable)',
                    ],
                    'Governance' => [
                        'Member / Manager Meeting Minutes',
                        'Resolutions',
                    ],
                ];

            case 'corporation':
                return [
                    'Formation' => [
                        'Articles of Incorporation',
                        'Bylaws',
                        'Initial Board Resolutions',
                    ],
                    'Shareholder & Board' => [
                        'Shareholder Meeting Minutes',
                        'Board Meeting Minutes',
                        'Stock Ledger / Ownership Records',
                    ],
                    'Tax & Filings' => [
                        'EIN Confirmation Letter',
                        'Franchise Tax Filings',
                        'Annual Reports',
                    ],
                    'Licenses & Permits' => [
                        'State Licenses',
                        'Local Permits',
                    ],
                    'Insurance' => [
                        'Corporate Insurance Policies',
                    ],
                ];

            case 'nonprofit':
                return [
                    'Formation & IRS' => [
                        'Certificate of Formation / Articles',
                        'Bylaws',
                        'IRS Determination Letter (501(c) status)',
                    ],
                    'Governance' => [
                        'Board Meeting Minutes',
                        'Committee Minutes (if applicable)',
                    ],
                    'Programs & Grants' => [
                        'Grant Agreements',
                        'Program Documentation',
                    ],
                    'Financial & Compliance' => [
                        'Budgets',
                        'Financial Statements',
                        'Annual Filings (IRS Form 990, state filings)',
                    ],
                ];

            case 'lp':
                return [
                    'Formation' => [
                        'Certificate of Limited Partnership',
                        'Partnership Agreement',
                    ],
                    'Investor & Asset Records' => [
                        'Investor Subscription Documents',
                        'Asset Acquisition Documents',
                    ],
                    'Financial & Compliance' => [
                        'Financial Statements',
                        'Tax Filings',
                    ],
                ];

            case 'llp':
            case 'partnership':
                return [
                    'Formation' => [
                        'Partnership Agreement',
                        'Registration Documents (if LLP)',
                    ],
                    'Governance' => [
                        'Partner Meeting Minutes',
                        'Resolutions',
                    ],
                    'Financial & Compliance' => [
                        'Tax Filings',
                        'Financial Statements',
                    ],
                ];

            case 'sole_proprietorship':
            default:
                return [
                    'Basic Records' => [
                        'DBA / Assumed Name Filings',
                        'Licenses & Permits',
                    ],
                    'Financial & Tax' => [
                        'Tax Filings',
                        'Financial Statements',
                    ],
                    'Insurance' => [
                        'Business Insurance Policies',
                    ],
                ];
        }
    }

    /**
     * POA-specific compliance requirements (Texas POA focus).
     */
    protected static function get_poa_requirements() {
        return [
            'Governing Documents' => [
                'Original CC&Rs (Declaration of Covenants, Conditions & Restrictions)',
                'All CC&R Amendments',
                'Original Bylaws',
                'All Bylaw Amendments',
                'Rules & Regulations',
                'Architectural Guidelines',
            ],
            'Procedural Records' => [
                'Notices of Meetings',
                'Notices of Rule Changes',
                'Ballots & Voting Records',
                'Adoption Procedures (Board and Owner votes)',
                'Minutes documenting adoption of changes',
            ],
            'Legal & Statutory' => [
                'Management Certificates (Texas Property Code 209 requirement)',
                'Attorney Correspondence',
                'Liens & Enforcement Records',
                'Foreclosure Notices (if any)',
                'Litigation Documents',
            ],
            'Contracts & Vendors' => [
                'Landscaping Contracts',
                'Maintenance/Repair Contracts',
                'Security Service Contracts',
                'Trash & Utility Service Agreements',
                'Insurance Policies',
                'Management Company Contracts',
                'Reserve Study Vendor Agreements',
            ],
            'Financial & Compliance' => [
                'Budgets',
                'Financial Statements',
                'Reserve Studies',
                'Tax Filings (if applicable)',
                'Annual Meeting Notices and Minutes',
            ],
        ];
    }
}