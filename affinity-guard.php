<?php
/**
 * Plugin Name:       Affinity Guard
 * Plugin URI:        https://github.com/affinitybridge/affinity-guard
 * Description:       Lets WordPress install its own security releases on sites deployed from git, and gives other security tooling something to hook.
 * Version:           2.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Trent Stromkins
 * Author URI:        https://affinitybridge.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       affinity-guard
 *
 * DEFAULTS
 *
 *   Enabled: yes.     The version control veto is lifted, so background updates run.
 *   Updates: 'minor'. Point releases such as 6.8.1 to 6.8.2 install themselves;
 *                     major and development releases do not.
 *
 * TO OVERRIDE, in wp-config.php, above the "That's all, stop editing" line:
 *
 *   define( 'AFFINITY_GUARD_ENABLED', false );      // true (default) | false
 *   define( 'AFFINITY_GUARD_UPDATES', 'major' );    // 'minor' (default) | 'major' | 'dev'
 *
 *   AFFINITY_GUARD_ENABLED false makes the plugin inert: it registers nothing
 *   and WordPress goes back to refusing all updates on a version-controlled site.
 *
 *   AFFINITY_GUARD_UPDATES is cumulative, each level including the ones before it:
 *
 *     'minor'  maintenance and security releases within a branch    6.8.1 -> 6.8.2
 *     'major'  the above, plus feature releases                     6.8   -> 6.9
 *     'dev'    the above, plus nightlies, alphas, betas and RCs     6.9-beta1 -> 6.9-beta2
 *
 *   'dev' will not move a stable site onto a beta. WordPress treats a site as a
 *   development version only when the version it is already running contains a
 *   hyphen, so that level applies to test installs on the nightly or beta
 *   channel and is ignored everywhere else. See the README for the detail.
 *
 * UPDATING THIS PLUGIN
 *
 *   By hand, or by whatever deploys your sites. Guard does not update itself:
 *   it makes no outbound requests and never writes to its own file. Releases
 *   are at https://github.com/affinitybridge/affinity-guard/releases.
 *
 * @package AffinityGuard
 */

namespace AffinityBridge\AffinityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Semantic: breaking changes to the constants or the hooks
 * documented in the README move the major number, and nothing else does.
 */
const VERSION = '2.0.0';

/**
 * Applied when the matching constant is not defined.
 */
const DEFAULT_ENABLED = true;
const DEFAULT_UPDATES = 'minor';

/**
 * The core update levels, each mapped to the release branches it permits.
 *
 * Levels are cumulative on purpose. A site that wants major releases installed
 * automatically certainly wants the security point releases too, so there is no
 * way to ask for one branch while excluding a safer one.
 */
const LEVELS = array(
	'minor' => array( 'minor' ),
	'major' => array( 'minor', 'major' ),
	'dev'   => array( 'minor', 'major', 'dev' ),
);

/*
 * Configuration
 * -----------------------------------------------------------------------
 */

/**
 * Whether the plugin should do anything at all.
 *
 * Deliberately not filterable. This is read while the file loads, before any
 * theme or plugin exists to filter it, so a hook here would look configurable
 * while doing nothing. Use the constant.
 *
 * @return bool True unless AFFINITY_GUARD_ENABLED says otherwise.
 */
function is_enabled() {
	return defined( 'AFFINITY_GUARD_ENABLED' )
		? (bool) constant( 'AFFINITY_GUARD_ENABLED' )
		: DEFAULT_ENABLED;
}

/**
 * The configured core update level.
 *
 * An unrecognised value falls back to the default rather than failing closed or
 * open, and says so when WP_DEBUG is on — a typo here would otherwise change a
 * site's update policy silently.
 *
 * @return string One of the LEVELS keys.
 */
function level() {
	$configured = defined( 'AFFINITY_GUARD_UPDATES' )
		? strtolower( trim( (string) constant( 'AFFINITY_GUARD_UPDATES' ) ) )
		: DEFAULT_UPDATES;

	if ( ! isset( LEVELS[ $configured ] ) ) {
		warn_once(
			sprintf(
				/* translators: 1: the configured value, 2: the list of valid values, 3: the fallback value. */
				esc_html__( 'AFFINITY_GUARD_UPDATES is set to %1$s, which is not one of %2$s. Falling back to %3$s.', 'affinity-guard' ),
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- showing the developer the value they actually set, which may be any type.
				esc_html( var_export( constant( 'AFFINITY_GUARD_UPDATES' ), true ) ),
				esc_html( implode( ', ', array_keys( LEVELS ) ) ),
				esc_html( DEFAULT_UPDATES )
			)
		);

		$configured = DEFAULT_UPDATES;
	}

	/**
	 * Filters the core update level.
	 *
	 * Resolved lazily when core asks about an update, so this is late enough
	 * for other plugins to hook. An unrecognised value is ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param string $configured One of 'minor', 'major' or 'dev'.
	 */
	$filtered = (string) apply_filters( 'affinity_guard_update_level', $configured );

	return isset( LEVELS[ $filtered ] ) ? $filtered : $configured;
}

/**
 * Whether a core release branch may install itself.
 *
 * @param string $branch One of 'minor', 'major' or 'dev'.
 * @return bool Whether the current level permits that branch.
 */
function allows( $branch ) {
	return in_array( $branch, LEVELS[ level() ], true );
}

/*
 * Core update policy
 * -----------------------------------------------------------------------
 */

/**
 * Tell the updater this install is not a version control checkout.
 *
 * WP_Automatic_Updater::is_vcs_checkout() walks up from the context directory
 * looking for .git, .svn, .hg or .bzr, and a single hit disables core, plugin,
 * theme and translation updates alike. Reporting false does not force any
 * update; it only lifts that veto, putting the site where a non-git site
 * already is.
 *
 * @see https://developer.wordpress.org/reference/hooks/automatic_updates_is_vcs_checkout/
 *
 * @param bool   $checkout Whether a checkout was discovered.
 * @param string $context  Filesystem path being checked.
 * @return bool Always false.
 */
function filter_vcs_checkout( $checkout, $context ) {
	return false;
}

/**
 * Allow or block minor core releases, such as 6.8.1 to 6.8.2.
 *
 * Core applies this filter to the value it has already resolved from the
 * auto_update_core_minor option and the WP_AUTO_UPDATE_CORE constant, so the
 * answer given here is the final one for this branch.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_minor( $enabled ) {
	return allows( 'minor' );
}

/**
 * Allow or block major core releases, such as 6.8 to 6.9.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_major( $enabled ) {
	return allows( 'major' );
}

/**
 * Allow or block development releases: nightlies, betas and release candidates.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_dev( $enabled ) {
	return allows( 'dev' );
}

/*
 * Housekeeping
 * -----------------------------------------------------------------------
 */

/**
 * Report a configuration problem once per request, under WP_DEBUG.
 *
 * @param string $message What went wrong.
 */
function warn_once( $message ) {
	static $warned = array();

	if ( isset( $warned[ $message ] ) || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	$warned[ $message ] = true;

	if ( function_exists( '_doing_it_wrong' ) ) {
		_doing_it_wrong( 'Affinity Guard', esc_html( $message ), esc_html( VERSION ) );
	}
}

/*
 * Bootstrap
 * -----------------------------------------------------------------------
 */

/*
 * Nothing is registered when the plugin is switched off, so a disabled site
 * behaves exactly as if the file were not there.
 *
 * Priority 100 on the core filters is late enough to win against a theme or
 * plugin that sets a blanket policy on the default priority, and early enough
 * that your own code can still override it.
 */
if ( is_enabled() ) {
	add_filter( 'automatic_updates_is_vcs_checkout', __NAMESPACE__ . '\\filter_vcs_checkout', 100, 2 );
	add_filter( 'allow_minor_auto_core_updates', __NAMESPACE__ . '\\filter_minor', 100 );
	add_filter( 'allow_major_auto_core_updates', __NAMESPACE__ . '\\filter_major', 100 );
	add_filter( 'allow_dev_auto_core_updates', __NAMESPACE__ . '\\filter_dev', 100 );

	/**
	 * Fires once Affinity Guard has registered everything it does.
	 *
	 * This is the integration point for other security tooling: at this moment
	 * every filter and hook documented in the README exists and can be
	 * overridden. Guard loads as a must-use plugin, so this fires before any
	 * ordinary plugin is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version The running version of Affinity Guard.
	 */
	do_action( 'affinity_guard_loaded', VERSION );
}
