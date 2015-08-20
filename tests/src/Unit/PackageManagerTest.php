<?php

/**
 * @file
 * Contains \Drupal\Tests\composer_manager\Unit\PackageManagerTest.
 */

namespace Drupal\Tests\composer_manager\Unit;

use Drupal\composer_manager\PackageManager;
use Drupal\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\composer_manager\PackageManager
 * @group composer_manager
 */
class PackageManagerTest extends UnitTestCase {

  /**
   * @var \Drupal\composer_manager\PackageManager
   */
  protected $manager;

  /**
   * Package fixtures.
   *
   * @var array
   */
  protected $packages = [
    'root' => [
      'name' => 'drupal/core',
      'type' => 'drupal-core',
      'require' => [
        'symfony/css-selector' => '2.6.*',
        'symfony/config' => '2.6.*',
        'symfony/intl' => '2.6.*',
        'symfony/dependency-injection' => '2.6.*',
      ],
    ],
    'core' => [
      'name' => 'drupal/core',
      'type' => 'drupal-core',
      'require' => [
        'symfony/dependency-injection' => '2.6.*',
      ],
    ],
    'extension' => [
      'commerce_kickstart' => [
        'name' => 'drupal/commerce_kickstart',
        'require' => [
          'symfony/css-selector' => '2.6.*',
        ],
      ],
      'test1' => [
        'name' => 'drupal/test1',
        'require' => [
          'symfony/intl' => '2.6.*',
        ],
      ],
      'test2' => [
        'name' => 'drupal/test2',
        'require' => [
          'symfony/config' => '2.6.*',
        ],
      ],
    ],
    'installed' => [
      [
        'name' => 'symfony/dependency-injection',
        'version' => 'v2.6.3',
        'description' => 'Symfony DependencyInjection Component',
        'homepage' => 'http://symfony.com',
      ],
      [
        'name' => 'symfony/event-dispatcher',
        'version' => 'v2.6.3',
        'description' => 'Symfony EventDispatcher Component',
        'homepage' => 'http://symfony.com',
        'require' => [
          // symfony/event-dispatcher doesn't really have this requirement,
          // we're lying for test purposes.
          'symfony/yaml' => 'dev-master',
        ],
      ],
      [
        'name' => 'symfony/yaml',
        'version' => 'dev-master',
        'source' => [
          'type' => 'git',
          'url' => 'https://github.com/symfony/Yaml.git',
          'reference' => '3346fc090a3eb6b53d408db2903b241af51dcb20',
        ],
        // description and homepage intentionally left out to make sure
        // getRequiredPackages(] can cope with that.
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $structure = [
      'core' => [
        'composer.json' => json_encode($this->packages['core']),
        'vendor' => [
          'composer' => [
            'installed.json' => json_encode($this->packages['installed']),
          ],
        ],
      ],
      'profiles' => [
        'commerce_kickstart' => [
          'commerce_kickstart.info.yml' => 'type: profile',
          'commerce_kickstart.profile' => '<?php',
          'composer.json' => json_encode($this->packages['extension']['commerce_kickstart']),
        ],
      ],
      'modules' => [
        'test1' => [
          'composer.json' => json_encode($this->packages['extension']['test1']),
          'test1.module' => '<?php',
          'test1.info.yml' => 'type: module',
        ],
      ],
      'sites' => [
        'all' => [
          'modules' => [
            'test2' => [
              'composer.json' => json_encode($this->packages['extension']['test2']),
              'test2.module' => '<?php',
              'test2.info.yml' => 'type: module',
            ],
          ],
        ],
      ],
    ];
    $root = vfsStream::setup('drupal', null, $structure);
    // Mock the root package builder and make it return our prebuilt fixture.
    $root_package_builder = $this->getMockBuilder('Drupal\composer_manager\RootPackageBuilderInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $root_package_builder->expects($this->any())
      ->method('build')
      ->will($this->returnValue($this->packages['root']));

    $this->manager = new PackageManager('vfs://drupal', $root_package_builder);

  }

  /**
   * @covers ::getCorePackage
   */
  public function testCorePackage() {
    $core_package = $this->manager->getCorePackage();
    $this->assertEquals($this->packages['core'], $core_package);
  }

  /**
   * @covers ::getExtensionPackages
   */
  public function testExtensionPackages() {
    $extension_packages = $this->manager->getExtensionPackages();
    $this->assertEquals($this->packages['extension'], $extension_packages);
  }

  /**
   * @covers ::getRequiredPackages
   * @covers ::processRequiredPackages
   */
  public function testRequiredPackages() {
    $expected_packages = [
      'symfony/css-selector' => [
        'constraint' => '2.6.*',
        'description' => '',
        'homepage' => '',
        'require' => [],
        'required_by' => ['drupal/commerce_kickstart'],
        'version' => '',
      ],
      'symfony/config' => [
        'constraint' => '2.6.*',
        'description' => '',
        'homepage' => '',
        'require' => [],
        'required_by' => ['drupal/test2'],
        'version' => '',
      ],
      'symfony/intl' => [
        'constraint' => '2.6.*',
        'description' => '',
        'homepage' => '',
        'require' => [],
        'required_by' => ['drupal/test1'],
        'version' => '',
      ],
      'symfony/dependency-injection' => [
        'constraint' => '2.6.*',
        'description' => 'Symfony DependencyInjection Component',
        'homepage' => 'http://symfony.com',
        'require' => [],
        'required_by' => ['drupal/core'],
        'version' => 'v2.6.3',
      ],
      'symfony/event-dispatcher' => [
        'constraint' => '',
        'description' => 'Symfony EventDispatcher Component',
        'homepage' => 'http://symfony.com',
        'require' => ['symfony/yaml' => 'dev-master'],
        'required_by' => [],
        'version' => 'v2.6.3',
      ],
      'symfony/yaml' => [
        'constraint' => 'dev-master',
        'description' => '',
        'homepage' => '',
        'require' => [],
        'required_by' => ['symfony/event-dispatcher'],
        'version' => 'dev-master#3346fc090a3eb6b53d408db2903b241af51dcb20',
      ],
    ];

    $required_packages = $this->manager->getRequiredPackages();
    $this->assertEquals($expected_packages, $required_packages);
  }

  /**
   * @covers ::needsComposerUpdate
   */
  public function testNeedsComposerUpdate() {
    $needs_update = $this->manager->needsComposerUpdate();
    $this->assertEquals(true, $needs_update);
  }

}
