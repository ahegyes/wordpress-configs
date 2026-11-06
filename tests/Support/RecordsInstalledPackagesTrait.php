<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Support;

trait RecordsInstalledPackagesTrait {
	/**
	 * @param array<array-key, mixed>       $root
	 * @param list<array<array-key, mixed>> $packages
	 */
	protected static function record_installed_packages( string $vendor_dir, array $root, array $packages ): void {
		$installed_json = array(
			'packages'          => $packages,
			'dev'               => true,
			'dev-package-names' => array(),
		);
		\file_put_contents( $vendor_dir . '/composer/installed.json', \json_encode( $installed_json, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES ) );

		// Composer records the root and every installed package, and every name one of them replaces or provides.
		$records  = array(
			'replace' => 'replaced',
			'provide' => 'provided',
		);
		$versions = array();
		foreach ( array( $root, ...$packages ) as $package ) {
			self::assertIsString( $package['name'] );
			$versions[ $package['name'] ]['dev_requirement'] = false;
			foreach ( $records as $link => $record ) {
				foreach ( (array) ( $package[ $link ] ?? array() ) as $target => $constraint ) {
					$versions[ $target ]['dev_requirement'] = false;
					$versions[ $target ][ $record ][]       = $constraint;
				}
			}
		}
		$installed_php = array(
			'root'     => array(
				'name' => $root['name'],
				'dev'  => true,
			),
			'versions' => $versions,
		);
		\file_put_contents( $vendor_dir . '/composer/installed.php', '<?php return ' . \var_export( $installed_php, true ) . ";\n" );
	}
}
