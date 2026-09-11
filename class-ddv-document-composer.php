<?php
/**
 * Creates 4 Document Types -- Certificate of Formation for LP, PA, PC,
 * and PLLC -- each referencing the shared Fact 452 (Title 1, Ch. 3,
 * Subch. A general formation basis) via boilerplate_reference.
 *
 * NOTE: boilerplate_reference, field_list, and variable_field_mapping
 * are registered meta only -- no consuming code reads them yet
 * (confirmed by direct search of the codebase, same as 447/448/451).
 * This is correct, verified scaffolding, not yet wired to any live
 * composer flow -- consistent with how 451 was built.
 *
 * Run via WP-CLI on the DDV server:
 *   wp eval-file create_doctypes_cert_of_formation.php
 */

$doc_types = [
    [
        'title' => 'Certificate of Formation (Limited Partnership)',
        'sos_form' => 'Form 207',
        'fields' => [
            'entity_name'                 => 'manual',
            'registered_agent_name'       => 'manual',
            'registered_agent_address'    => 'manual',
            'organizer_name'              => 'manual',
            'general_partner_name'        => 'manual',
            'duration'                    => 'manual',
            'sos_form_number'             => 'auto',
            'date_of_document'            => 'auto',
        ],
    ],
    [
        'title' => 'Certificate of Formation (Professional Association)',
        'sos_form' => 'Form 204',
        'fields' => [
            'entity_name'                 => 'manual',
            'registered_agent_name'       => 'manual',
            'registered_agent_address'    => 'manual',
            'organizer_name'              => 'manual',
            'licensed_profession'         => 'manual',
            'governing_professional_board'=> 'manual',
            'duration'                    => 'manual',
            'sos_form_number'             => 'auto',
            'date_of_document'            => 'auto',
        ],
    ],
    [
        'title' => 'Certificate of Formation (Professional Corporation)',
        'sos_form' => 'Form 203',
        'fields' => [
            'entity_name'                 => 'manual',
            'registered_agent_name'       => 'manual',
            'registered_agent_address'    => 'manual',
            'incorporator_name'           => 'manual',
            'licensed_profession'         => 'manual',
            'shares_authorized'           => 'manual',
            'duration'                    => 'manual',
            'sos_form_number'             => 'auto',
            'date_of_document'            => 'auto',
        ],
    ],
    [
        'title' => 'Certificate of Formation (Professional Limited Liability Company)',
        'sos_form' => 'Form 206',
        'fields' => [
            'entity_name'                 => 'manual',
            'registered_agent_name'       => 'manual',
            'registered_agent_address'    => 'manual',
            'organizer_name'              => 'manual',
            'licensed_profession'         => 'manual',
            'initial_members_or_managers' => 'manual',
            'duration'                    => 'manual',
            'sos_form_number'             => 'auto',
            'date_of_document'            => 'auto',
        ],
    ],
];

foreach ( $doc_types as $dt ) {
    $post_id = wp_insert_post( [
        'post_type'   => 'ddv_document_type',
        'post_status' => 'publish',
        'post_title'  => $dt['title'],
    ], true );

    if ( is_wp_error( $post_id ) ) {
        WP_CLI::warning( "Failed on '{$dt['title']}': " . $post_id->get_error_message() );
        continue;
    }

    update_post_meta( $post_id, 'field_list', json_encode( array_keys( $dt['fields'] ) ) );
    update_post_meta( $post_id, 'boilerplate_reference', json_encode( [
        'tex-bus-orgs-title1-ch3-subchA-formation-basis',
    ] ) );
    update_post_meta( $post_id, 'variable_field_mapping', json_encode( $dt['fields'] ) );

    WP_CLI::success( "Created '{$dt['title']}' -- post ID: $post_id (SOS {$dt['sos_form']})" );
}