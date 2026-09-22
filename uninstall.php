<?php
/**
 * Uninstall cleanup for Sole Engine WP.
 *
 * WordPress loads this file in isolation (NOT the plugin) when the plugin is
 * deleted from the admin. It must therefore be self-contained: it does not
 * bootstrap core.php or reference any plugin class, only the WP runtime.
 *
 * Removes the plugin's entire durable footprint so deletion leaves no orphans:
 *   - every option (config, diagnostics, indexing/poller state) and cached
 *     transient, matched by the shared "sole_engine_" prefix;
 *   - the three scheduled cron hooks;
 *   - the per-post indexed-content-hash meta.
 *
 * Option cleanup is prefix-based ON PURPOSE: every option this plugin writes is
 * named "sole_engine_*", so a single LIKE deletes all current AND future
 * options with no hand-maintained list to fall out of sync. Cron hooks have no
 * wildcard primitive in WP, so those three are necessarily explicit.
 *
 * Scope: the current site only. Multisite network installs would orphan
 * per-site data on the other sites; looping get_sites()/switch_to_blog() is
 * deferred until this plugin is actually network-activated (see map.md).
 */

declare(strict_types=1);

namespace SoleEngineWP;

// Guard: only ever run inside WordPress's uninstall flow. Without this a direct
// request to the file would execute the deletes.
if (!\defined("WP_UNINSTALL_PLUGIN")) {
    exit;
}

global $wpdb;

// Delete all plugin options AND their cached transients in one prepared query.
// Transients live in the options table under the "_transient_" /
// "_transient_timeout_" prefixes, so the bare "sole_engine_%" pattern would
// miss them — hence the three patterns.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like("sole_engine_") . "%",
        $wpdb->esc_like("_transient_sole_engine_") . "%",
        $wpdb->esc_like("_transient_timeout_sole_engine_") . "%"
    )
);

// Clear scheduled cron events. No prefix primitive exists for cron, so each of
// the plugin's three hooks is cleared explicitly.
foreach (
    [
        "sole_engine_bulk_index_batch",
        "sole_engine_reindex_poll",
        "sole_engine_space_poll",
    ] as $sole_engine_hook
) {
    \wp_clear_scheduled_hook($sole_engine_hook);
}

// Drop the per-post indexed-content-hash meta across the whole corpus.
\delete_post_meta_by_key("sole_engine_indexed_hash");
