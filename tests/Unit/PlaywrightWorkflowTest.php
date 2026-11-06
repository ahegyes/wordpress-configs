<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\TestCase;

final class PlaywrightWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	public function test_the_installed_playwright_version_keys_the_browser_cache(): void {
		$this->write_project( array( 'node_modules/@playwright/test/package.json' => '{"name": "@playwright/test", "version": "1.64.1"}' ) );

		$result = $this->run_step( 'reusable-playwright-e2e.yml', 'e2e', 'Resolve Playwright version', array() );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( array( 'version' => '1.64.1' ), $result['outputs'] );
	}

	public function test_a_project_without_playwright_fails_with_a_named_error(): void {
		$result = $this->run_step( 'reusable-playwright-e2e.yml', 'e2e', 'Resolve Playwright version', array() );

		self::assertSame( 1, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::@playwright\/test is not installed/m', $result['output'] );
		self::assertSame( array(), $result['outputs'] );
	}
}
