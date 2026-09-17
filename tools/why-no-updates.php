<?php
/**
 * Why has this site not updated itself?
 *
 * Walks the gates a core auto-update has to pass, in the order WordPress
 * consults them, and reports the ones that are shut.
 *
 * This file is never installed on a site. Pipe it in from elsewhere, so WP-CLI
 * reads it over stdin and runs it in memory, leaving nothing behind:
 *
 *     ssh prod 'cd /var/www/site && wp eval-file -' < tools/why-no-updates.php
 *
 * Read only. It changes no options, no files and no schedules.
 *
 * @package AffinityGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this through WP-CLI: wp eval-file - < why-no-updates.php\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

// WP-CLI includes this file inside a function, so a plain top level variable
// here is not a global and `global $blockers` in gate() would bind to an empty
// one. Address the superglobal directly so the scope cannot drift.
$GLOBALS['ag_blockers'] = array();

/**
 * Print one checked gate.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it permits updates.
 * @param string $detail What was found.
 * @param string $why    Added to the verdict when $ok is false.
 */
function gate( $label, $ok, $detail, $why = '' ) {
	printf( "  [%s] %-34s %s\n", $ok ? 'ok' : 'XX', $label, $detail );
	if ( ! $ok && '' !== $why ) {
		$GLOBALS['ag_blockers'][] = $why;
	}
}

echo "\n== Affinity Guard ==\n";

$guard = defined( 'AffinityBridge\AffinityGuard\VERSION' );

if ( $guard ) {
	$enabled = AffinityBridge\AffinityGuard\is_enabled();
	gate( 'installed', true, 'version ' . AffinityBridge\AffinityGuard\VERSION );
	gate( 'enabled', $enabled, $enabled ? 'yes' : 'AFFINITY_GUARD_ENABLED is false', 'Guard is switched off, so the version control veto still applies.' );
	if ( $enabled ) {
		gate( 'update level', true, AffinityBridge\AffinityGuard\level() );
	}
} else {
	gate( 'installed', false, 'not loaded', 'Affinity Guard is not installed, so WordPress blocks updates on a version controlled site.' );
}

echo "\n== Hard stops ==\n";

$updater = new WP_Automatic_Updater();

$disabled_const = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;
gate( 'AUTOMATIC_UPDATER_DISABLED', ! $disabled_const, $disabled_const ? 'true' : 'not set', 'AUTOMATIC_UPDATER_DISABLED is true in wp-config.php.' );

$file_mods = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
gate( 'DISALLOW_FILE_MODS', ! $file_mods, $file_mods ? 'true' : 'not set', 'DISALLOW_FILE_MODS is true, which forbids all file changes.' );

$is_disabled = $updater->is_disabled();
gate( 'WP_Automatic_Updater::is_disabled', ! $is_disabled, $is_disabled ? 'yes' : 'no', 'The updater reports itself disabled, usually via the automatic_updater_disabled filter.' );

echo "\n== Filesystem ==\n";

$method = get_filesystem_method();
gate( 'filesystem method', 'direct' === $method, $method, sprintf( 'Filesystem method is %s, not direct. Background updates only run when PHP can write files itself, without credentials.', $method ) );

gate( 'ABSPATH writable', is_writable( ABSPATH ), is_writable( ABSPATH ) ? 'yes' : ABSPATH . ' is read only', 'The WordPress directory is not writable by PHP.' );

$vcs = $updater->is_vcs_checkout( ABSPATH );
gate( 'version control veto', ! $vcs, $vcs ? 'a checkout was found' : 'lifted', 'WordPress found a .git/.svn/.hg/.bzr directory and is refusing to update. This is the veto Guard exists to lift.' );

echo "\n== Cron ==\n";

$no_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
gate( 'DISABLE_WP_CRON', ! $no_cron, $no_cron ? 'true — a real cron must call wp-cron.php' : 'not set', 'DISABLE_WP_CRON is true. Nothing runs unless a system cron calls wp-cron.php, so confirm that cron exists.' );

$next = wp_next_scheduled( 'wp_version_check' );

if ( $next ) {
	$overdue = time() - $next;
	gate(
		'wp_version_check scheduled',
		$overdue < DAY_IN_SECONDS,
		$overdue > 0
			? sprintf( 'overdue by %s', human_time_diff( $next ) )
			: sprintf( 'due in %s', human_time_diff( $next ) ),
		'The update check is more than a day overdue, so cron is not running at all. On a low traffic site nothing fires until someone visits.'
	);
} else {
	gate( 'wp_version_check scheduled', false, 'not scheduled', 'No update check is scheduled. Something has cleared it.' );
}

$lock = get_option( 'auto_updater.lock' );
$held = $lock && ( time() - $lock ) < HOUR_IN_SECONDS;
gate( 'updater lock', ! $held, $held ? sprintf( 'held, %d minutes left', (int) ( ( HOUR_IN_SECONDS - ( time() - $lock ) ) / 60 ) ) : 'free', 'An update run is in progress or crashed partway. The lock clears an hour after it was taken.' );

echo "\n== History ==\n";

$failed = get_site_option( 'auto_core_update_failed' );

if ( $failed ) {
	gate(
		'last attempt',
		false,
		sprintf( '%s -> %s failed', $failed['current'] ?? '?', $failed['attempted'] ?? '?' ),
		sprintf( 'A previous update failed (%s). WordPress will not retry the same version automatically; clear it with: wp option delete auto_core_update_failed', $failed['error_code'] ?? 'no code' )
	);
} else {
	gate( 'last attempt', true, 'no recorded failure' );
}

echo "\n== What is on offer ==\n";

wp_version_check( array(), true );
$updates = get_site_transient( 'update_core' );
$offer   = null;

foreach ( (array) ( $updates->updates ?? array() ) as $update ) {
	if ( 'upgrade' === $update->response ) {
		$offer = $update;
		break;
	}
}

require ABSPATH . WPINC . '/version.php';
printf( "  running WordPress %s\n", $wp_version );

if ( ! $offer ) {
	printf( "  nothing offered — this site is already current\n" );
} else {
	printf( "  offered %s\n", $offer->current );

	$should = Core_Upgrader::should_update_to_version( $offer->current );
	gate(
		'update policy permits it',
		$should,
		$should ? 'yes' : 'refused by the minor/major/dev policy',
		sprintf( 'The offered version %s is outside the configured update level. Raise AFFINITY_GUARD_UPDATES if you want it applied automatically.', $offer->current )
	);
}

echo "\n== Verdict ==\n";

if ( ! $GLOBALS['ag_blockers'] ) {
	echo "  Nothing is blocking updates. If the site is still behind, it is waiting\n";
	echo "  for the next cron run: wp cron event run wp_version_check\n\n";
} else {
	foreach ( $GLOBALS['ag_blockers'] as $i => $reason ) {
		printf( "  %d. %s\n", $i + 1, $reason );
	}
	echo "\n";
}
