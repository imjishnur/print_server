<?php
/**
 * BIZPOZ Print Server v2 - Configuration Settings
 */
return [
    // Open access mode (no API key required)
    'security_enabled' => false,

    // API Key (inactive when security_enabled is false)
    'api_key' => '',

    // Allowed IPs (empty = open to all local network IPs)
    'allowed_ips' => [],

    // Default printer settings
    'default_paper_width' => 48, // 80mm paper width (48 chars)
    'reverse_feed_dots' => 36,  // Reverse paper feed (~4.5mm backward)

    // Print logs: every print job is saved with a preview, see /logs
    'logs_enabled' => true,
    'log_days' => 7, // logs older than this are deleted
];
