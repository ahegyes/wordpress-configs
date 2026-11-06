<?php declare( strict_types=1 );

namespace Acme\Commerce;

use Acme\Commerce\Formats;
use Acme\Commerce\Formats\{Csv};
use Psr\Log\LoggerInterface;

/**
 * Labels a WooCommerce settings section through WooCommerce, Action Scheduler and host-plugin calls.
 */
final class SettingsSection extends \Automattic\WooCommerce\Admin\Settings\SettingsSection {
	// region GETTERS

	/**
	 * Returns the section label.
	 *
	 * @return  string
	 */
	public function get_label(): string {
		\wc_get_logger()->info( 'Rendering.' );
		\as_enqueue_async_action( 'acme_commerce_rendered' );
		\host_plugin_setting();
		$order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
		$formats    = 'Acme\\Commerce\\Formats\\';
		$csv        = '\\Acme\\Commerce\\Formats\\Csv';
		$prefix     = \Acme\Commerce\get_label_prefix() . \Acme\Commerce\LABEL;

		return \__( 'Commerce', 'acme-commerce' );
	}

	// endregion

	// region METHODS

	/**
	 * Returns an export format, named by its class.
	 *
	 * @param   string $type The short name of the format class.
	 *
	 * @return  object
	 */
	public static function format( string $type ): object {
		$class = "Acme\\Commerce\\Formats\\$type";

		return new $class();
	}

	/**
	 * Logs that the section rendered.
	 *
	 * @param   LoggerInterface $logger The logger.
	 */
	public function log( LoggerInterface $logger ): void {
		$logger->info( 'Rendered.' );
	}

	// endregion
}
