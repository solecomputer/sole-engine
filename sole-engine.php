<?php
/**
 * Plugin Name:       Sole Engine - AI LLM Provider
 * Plugin URI:        https://sole.computer/wordpress/engine/
 * Description:       A site-wide AI engine for WordPress: one key, one place to configure, and a local API that powers AI features across your plugins.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Sole Computer
 * Author URI:        https://sole.computer/
 * License:           AGPL-3.0-only
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/core.php";

\add_action("plugins_loaded", function (): void {
    (new Core_Plugin())->boot();
});
