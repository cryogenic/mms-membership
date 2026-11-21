<?php
/**
 * GitHub release updates through Plugin Update Checker.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Updater
 */
class MMS_Updater {

	/**
	 * GitHub repository URL, e.g. https://github.com/owner/mms-membership/.
	 * Override with the MMS_GITHUB_REPO constant or the mms_github_repo_url filter.
	 */
	const DEFAULT_REPO_URL = 'https://github.com/cryogenic/mms-membership/';

	/**
	 * Register the update checker and disable auto-updates.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'auto_update_plugin', array( __CLASS__, 'disable_auto_update' ), 10, 2 );

		$url = self::repo_url();

		if ( '' === $url || ! class_exists( 'YahnisElsts\PluginUpdateChecker\v6\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker(
			$url,
			MMS_MEMBERSHIP_FILE,
			'mms-membership',
			12
		);

		if ( defined( 'MMS_GITHUB_TOKEN' ) && '' !== (string) MMS_GITHUB_TOKEN ) {
			$checker->setAuthentication( (string) MMS_GITHUB_TOKEN );
		}

		$api = method_exists( $checker, 'getVcsApi' ) ? $checker->getVcsApi() : null;

		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/mms-membership-.*\.zip($|[?&#])/i' );
		}
	}

	/**
	 * Resolve the GitHub repository URL.
	 *
	 * @return string
	 */
	public static function repo_url() {
		$url = defined( 'MMS_GITHUB_REPO' ) ? (string) MMS_GITHUB_REPO : self::DEFAULT_REPO_URL;

		/**
		 * Filter the GitHub repository URL used for plugin updates.
		 *
		 * @param string $url Repository URL.
		 */
		$url = (string) apply_filters( 'mms_github_repo_url', $url );

		return '' !== $url ? trailingslashit( $url ) : '';
	}

	/**
	 * Keep plugin updates manual so migrations never run unattended.
	 *
	 * @param bool   $update Whether to auto-update the plugin.
	 * @param object $item   Plugin update offer.
	 * @return bool
	 */
	public static function disable_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( MMS_MEMBERSHIP_FILE ) === $item->plugin ) {
			return false;
		}

		return $update;
	}
}
