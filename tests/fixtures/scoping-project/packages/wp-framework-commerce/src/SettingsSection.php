<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Commerce;

/**
 * Extends a WooCommerce settings section and calls WooCommerce and Action Scheduler functions.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
class SettingsSection extends \Automattic\WooCommerce\Admin\Settings\SettingsSection {
	/**
	 * Returns the section label.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	public function get_label(): string {
		\wc_get_logger()->info( 'Rendering.' );
		\as_enqueue_async_action( 'dws_commerce_rendered' );

		return \__( 'Commerce', 'wp-framework' );
	}
}
