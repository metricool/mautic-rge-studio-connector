<?php

declare(strict_types=1);

return [
    'name'        => 'RGE Studio Connector',
    'description' => 'Turns RGE Studio (formerly Beefree) Custom Connector exports into Mautic emails.',
    'version'     => '1.0.0',
    'author'      => 'Metricool',
    'parameters' => [
        'rge_studio_connector_secret'                    => '',
        'rge_studio_connector_previous_secret'           => '',
        'rge_studio_connector_secret_header'             => 'X-Webhook-Secret',
        'rge_studio_connector_user_id'                   => 0,
        'rge_studio_connector_project'                   => 'RGE Studio',
        'rge_studio_connector_campaign_tag'              => 'mautic-campaign',
        'rge_studio_connector_segment_tag'               => 'mautic-segment',
        'rge_studio_connector_accepted_statuses'         => [],
        'rge_studio_connector_marker_unsubscribe'        => '[FIX UNSUBSCRIBE]',
        'rge_studio_connector_marker_images'             => '[FIX IMAGES]',
        'rge_studio_connector_marker_other'              => '[FIX EMAIL]',
        'rge_studio_connector_image_folder'              => 'rge-studio',
        'rge_studio_connector_designer_hosts'            => ['media.beefree.cloud', 'imgdist.com'],
        'rge_studio_connector_pasted_unsubscribe_paths'  => ['/email/unsubscribe/', '/email/dnc/'],
        'rge_studio_connector_max_body_bytes'            => 10485760,
        'rge_studio_connector_max_image_bytes'           => 5242880,
        'rge_studio_connector_max_images'                => 150,
        'rge_studio_connector_image_seconds'             => 25,
        'rge_studio_connector_image_attempts'            => 2,
        'rge_studio_connector_claim_seconds'             => 60,
        'rge_studio_connector_wait_seconds'              => 35,
    ],
];
