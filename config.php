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

    // -------------------------------------------------------------
    // Auto-Update Settings
    // -------------------------------------------------------------
    'auto_update' => [
        // Master switch. Set to false to disable checking/updating entirely.
        'enabled' => true,

        // How often to check GitHub for a new version.
        // Format: number + unit, e.g. '10m', '30m', '1h', '2h', '1d'.
        // Bare numbers are treated as seconds. Minimum enforced is 60s.
        'check_interval' => '1h',

        // Where to check for updates.
        'github_owner' => 'imjishnur',
        'github_repo'  => 'print_server',
        'branch'       => 'main', // if this branch 404s, 'main' then 'master' are tried automatically

        // Paths (relative to the project root) that auto-update will NEVER touch,
        // even if the file changed upstream. Add anything with local/site-specific
        // data here (your own custom includes, images, etc).
        'exclude' => [
            'config.php',
            '.git',
            '.gitignore',
            'backups',
            'logs',
            'update.log',
            'VERSION',
            '.update_state.json',
            'update.lock',
        ],

        // If true, files that were deleted upstream also get deleted locally.
        // Left off by default: safer to leave orphaned local files than to delete
        // something unexpectedly.
        'remove_deleted_files' => false,

        // Keep a timestamped backup of every file before it's overwritten.
        'backup_enabled' => true,

        // How many backup folders (one per update run) to keep before pruning
        // the oldest ones.
        'max_backups' => 10,

        // Network timeouts, in seconds.
        'connect_timeout' => 10,
        'request_timeout' => 30,
    ],
];
