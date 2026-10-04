<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Commerce;

/**
 * Labels a WooCommerce settings section through WooCommerce and Action Scheduler calls.
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
		\as_enqueue_async_action( 'dws_commerce_rendered' );

		return \__( 'Commerce', 'wp-framework' );
	}

	// endregion
}
