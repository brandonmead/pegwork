<?php
/**
 * Collects schema packages during the pegwork_register action.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

/**
 * Passed to `pegwork_register`. Packages load in the order they are added.
 *
 * Example: add_action( 'pegwork_register', fn( Pegwork\Api\RegistryBuilder $b ) => $b->add_package( __DIR__ . '/schema' ) );
 */
final class RegistryBuilder {

	/**
	 * Package directories in registration order.
	 *
	 * @var string[]
	 */
	private array $packages = array();

	/**
	 * Adds a package directory (holding `manifest.json`).
	 *
	 * @param string $dir Package directory.
	 * @return $this
	 */
	public function add_package( string $dir ): self {
		$this->packages[] = $dir;

		return $this;
	}

	/**
	 * Package directories in registration order.
	 *
	 * @return string[]
	 */
	public function packages(): array {
		return $this->packages;
	}
}
