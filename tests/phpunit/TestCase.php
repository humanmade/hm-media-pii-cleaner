<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case: wires Brain\Monkey and Mockery lifecycle into PHPUnit.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp() : void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown() : void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
