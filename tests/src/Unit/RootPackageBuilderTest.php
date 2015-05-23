<?php

/**
 * @file
 * Contains \Drupal\Tests\composer_manager\Unit\RootPackageBuilderTest.
 */

namespace Drupal\Tests\composer_manager\Unit;

use Drupal\composer_manager\RootPackageBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\composer_manager\RootPackageBuilder
 * @group composer_manager
 */
class RootPackageBuilderTest extends UnitTestCase {

  /**
   * @var \Drupal\composer_manager\RootPackageBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->builder = new RootPackageBuilder('/');
  }

  /**
   * @covers ::build
   * @covers ::filterPlatformPackages
   * @covers ::resolveMinimumStabilityProperty
   * @covers ::resolvePreferStableProperty
   */
  public function testBuild() {
    $core_package = array(
      'name' => 'drupal/core',
      'type' => 'drupal-core',
      'license' => 'GPL-2.0+',
      'require' => array(
        'sdboyer/gliph' => '0.1.*',
        'symfony/class-loader' => '2.6.*',
        'symfony/css-selector' => '2.6.*',
        'symfony/dependency-injection' => '2.6.*',
      ),
    );
    $extension_packages = array(
      'test1' => array(
        'name' => 'drupal/test1',
        'require' => array(
          'symfony/intl' => '2.6.*',
          'php' => '~5.5',
          'ext-intl' => '*',
        ),
        'minimum-stability' => 'rc',
        'repositories' => array(
          array(
            'type' => 'pear',
            'url' => 'http://pear2.php.net',
          ),
        ),
      ),
      'test2' => array(
        'name' => 'drupal/test2',
        'require' => array(
          'symfony/class-loader' => '2.5.*',
          'symfony/config' => '2.6.*',
        ),
        'minimum-stability' => 'beta',
        'prefer-stable' => FALSE,
        'repositories' => array(
          array(
            'type' => 'pear',
            'url' => 'http://pear2.php.net',
          ),
        ),
      ),
      'test3' => array(
        'name' => 'drupal/test3',
        'repositories' => array(
          array(
            'type' => 'composer',
            'url' => 'http://packages.example.com',
          ),
        ),
      ),
    );
    $root_package = $this->builder->build($core_package, $extension_packages);

    // Confirm that the root package has preserved the core package data.
    $this->assertEquals($core_package['name'], $root_package['name']);
    $this->assertEquals($core_package['type'], $root_package['type']);
    $this->assertEquals($core_package['license'], $root_package['license']);
    // Confirm that the valid test1 and test2 requirements were merged.
    $this->assertCount(6, $root_package['require']);
    $this->assertEquals('2.6.*', $root_package['require']['symfony/intl']);
    $this->assertEquals('2.6.*', $root_package['require']['symfony/config']);
    // Confirm that test2 was unable to change a core dependency.
    $this->assertEquals('2.6.*', $root_package['require']['symfony/class-loader']);
    // Confirm that the the test1 and test2 repositories were deduplicated,
    // and the test3 ones were ignored because the package has no requirements.
    $this->assertCount(1, $root_package['repositories']);
    $this->assertEquals('pear', $root_package['repositories'][0]['type']);
    // Confirm that the platform packages were ignored.
    $this->assertTrue(!isset($root_package['require']['php']));
    $this->assertTrue(!isset($root_package['require']['ext-intl']));
    // Confirm that minimum-stability was resolved.
    $this->assertEquals('beta', $root_package['minimum-stability']);
    // Confirm that prefer-stable was resolved.
    $this->assertEquals(FALSE, $root_package['prefer-stable']);
    // Confirm that the drupal-update command was added.
    $this->assertNotEmpty($root_package['autoload']['psr-4']['Drupal\\composer_manager\\Composer\\']);
    $this->assertNotEmpty($root_package['scripts']['drupal-update']);
    // Confirm that generation info was added.
    $this->assertStringStartsWith('Generated by composer_manager', $root_package['extra']['_generator']);
    $this->assertEquals('drupal/core, drupal/test1, drupal/test2', $root_package['extra']['_sources']);
  }

}
