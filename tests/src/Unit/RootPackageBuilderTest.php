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
    parent::setUp();
    $this->builder = new RootPackageBuilder($this->root);
  }

  /**
   * @covers ::build
   * @covers ::filterPlatformPackages
   * @covers ::resolveMinimumStabilityProperty
   * @covers ::resolvePreferStableProperty
   * @covers ::rebaseAutoloadPaths
   */
  public function testBuild() {
    $core_package = [
      'name' => 'drupal/core',
      'type' => 'drupal-core',
      'license' => 'GPL-2.0+',
      'require' => [
        'sdboyer/gliph' => '0.1.*',
        'symfony/class-loader' => '2.6.*',
        'symfony/css-selector' => '2.6.*',
        'symfony/dependency-injection' => '2.6.*',
      ],
      'replace' => [
        'drupal/aggregator' => 'self.version',
      ],
      'scripts' => [
        'pre-autoload-dump' => 'Drupal\\Core\\Composer\\Composer::preAutoloadDump',
        'post-autoload-dump' => 'Drupal\\Core\\Composer\\Composer::ensureHtaccess',
      ],
      'autoload' => [
        'psr-4' => [
          'Drupal\\Core\\' => 'lib/Drupal/Core',
          'Drupal\\Component\\' => 'lib/Drupal/Component',
        ],
        'files' => [
          'lib/Drupal.php',
        ],
      ],
    ];
    $extension_packages = [
      'test1' => [
        'name' => 'drupal/test1',
        'require' => [
          'symfony/intl' => '2.6.*',
          'php' => '~5.5',
          'ext-intl' => '*',
        ],
        'minimum-stability' => 'rc',
        'repositories' => [
          [
            'type' => 'pear',
            'url' => 'http://pear2.php.net',
          ],
        ],
      ],
      'test2' => [
        'name' => 'drupal/test2',
        'require' => [
          'symfony/class-loader' => '2.5.*',
          'symfony/config' => '2.6.*',
        ],
        'minimum-stability' => 'beta',
        'prefer-stable' => FALSE,
        'repositories' => [
          [
            'type' => 'pear',
            'url' => 'http://pear2.php.net',
          ],
        ],
      ],
      'test3' => [
        'name' => 'drupal/test3',
        'repositories' => [
          [
            'type' => 'composer',
            'url' => 'http://packages.example.com',
          ],
        ],
      ],
      'test4' => [
        'name' => 'drupal/test4',
        'require' => [
          'symfony/class-loader' => '2.5.*',
          'symfony/config' => '2.6.*',
        ],
        'repositories' => [
          [
            'type' => 'composer',
            'url' => 'http://packages.example.com',
          ],
        ],
      ],
    ];
    $root_package = $this->builder->build($core_package, $extension_packages);

    // Confirm that the root package has a valid name and type.
    $this->assertEquals('drupal/drupal', $root_package['name']);
    $this->assertEquals('project', $root_package['type']);
    // Confirm the expected requirements.
    $this->assertCount(7, $root_package['require']);
    $this->assertEquals('^1.0.20', $root_package['require']['composer/installers']);
    $this->assertEquals('2.6.*', $root_package['require']['symfony/intl']);
    $this->assertEquals('2.6.*', $root_package['require']['symfony/config']);
    // Confirm that test2 was unable to change a core dependency.
    $this->assertEquals('2.6.*', $root_package['require']['symfony/class-loader']);
    // Confirm that the the test1 and test2 repositories were deduplicated.
    $this->assertCount(2, $root_package['repositories']);
    $this->assertEquals('composer', $root_package['repositories'][0]['type']);
    $this->assertEquals('pear', $root_package['repositories'][1]['type']);
    // Confirm that the platform packages were ignored.
    $this->assertTrue(!isset($root_package['require']['php']));
    $this->assertTrue(!isset($root_package['require']['ext-intl']));
    // Confirm the expected replaced packages.
    $expected = $core_package['replace'] + ['drupal/core' => 'self.version'];
    $this->assertEquals($expected, $root_package['replace']);
    // Confirm that minimum-stability was resolved.
    $this->assertEquals('beta', $root_package['minimum-stability']);
    // Confirm that prefer-stable was resolved.
    $this->assertEquals(FALSE, $root_package['prefer-stable']);
    // Confirm the expected scripts.
    $this->assertCount(6, $root_package['scripts']);
    $this->assertArrayHasKey('pre-autoload-dump', $root_package['scripts']);
    $this->assertArrayHasKey('post-autoload-dump', $root_package['scripts']);
    $this->assertArrayHasKey('post-install-cmd', $root_package['scripts']);
    $this->assertArrayHasKey('post-update-cmd', $root_package['scripts']);
    $this->assertArrayHasKey('drupal-rebuild', $root_package['scripts']);
    $this->assertArrayHasKey('drupal-update', $root_package['scripts']);
    $this->assertArrayHasKey('drupal-install', $root_package['scripts']);
    // Confirm the autoload paths.
    $expected = [
      'psr-4' => [
        'Drupal\\Core\\' => 'core/lib/Drupal/Core',
        'Drupal\\Component\\' => 'core/lib/Drupal/Component',
        'Drupal\\composer_manager\\Composer\\' => 'modules/composer_manager/src/Composer',
      ],
      'files' => [
        'core/lib/Drupal.php',
      ],
    ];
    $this->assertEquals($expected, $root_package['autoload']);
    // Confirm that generation info was added.
    $this->assertEquals('Generated by composer_manager', $root_package['extra']['_generator']);
    $this->assertEquals('drupal/core, drupal/test1, drupal/test2, drupal/test4', $root_package['extra']['_sources']);
  }

}
