<?php
/**
 * Plugin Name: Supreme Autoparts Loader
 * Description: Ensures core plugin branding filters load early when the regular plugin is present.
 * Version: 1.0.0
 */

declare(strict_types=1);

// Intentionally minimal — main logic lives in supreme-autoparts-core.
if (!defined('ABSPATH')) {
    exit;
}
