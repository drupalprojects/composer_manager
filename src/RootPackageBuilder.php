<?php

/**
 * @file
 * Contains \Drupal\composer_manager\RootPackageBuilder.
 */

namespace Drupal\composer_manager;

/**
 * Builds the root package.
 */
class RootPackageBuilder implements RootPackageBuilderInterface {

  /**
   * Maps stability constraints to integers for comparison purposes.
   *
   * @var array
   */
  protected $stability = [
    'dev' => 0,
    'alpha' => 1,
    'beta' => 2,
    'RC' => 3,
    'rc' => 3,
    'stable' => 4,
  ];

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * Constructs a RootPackageBuilder object.
   *
   * @param string $root
   *   The app root.
   */
  public function __construct($root) {
    $this->root = $root;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $core_package, array $extension_packages) {
    $sources = [$core_package['name']];
    // Add defaults for any properties not declared by the core package.
    $core_package += [
      'minimum-stability' => 'stable',
      'prefer-stable' => TRUE,
      'repositories' => [],
    ];
    // Collect properties from all packages, starting with the core package.
    $properties = [
      'require' => $core_package['require'],
      'require-dev' => isset($core_package['require-dev']) ? $core_package['require-dev'] : [],
      'minimum-stability' => [$core_package['minimum-stability']],
      'prefer-stable' => [$core_package['prefer-stable']],
      'repositories' => $core_package['repositories'],
    ];
    // Re-add composer/installers, present in the original root package, to
    // keep the ability for modules to require other drupal projects.
    $properties['require']['composer/installers'] = '^1.0.20';

    foreach ($extension_packages as $extension_name => $extension_package) {
      if (empty($extension_package['name']) || ((empty($extension_package['require']) && empty($extension_package['require-dev'])))) {
        // Each package must have a name and at least one requirement.
        continue;
      }

      $sources[] = $extension_package['name'];
      if (isset($extension_package['require'])) {
        $properties['require'] = array_merge($extension_package['require'], $properties['require']);
      }
      if (isset($extension_package['require-dev'])) {
        $properties['require-dev'] = array_merge($extension_package['require-dev'], $properties['require-dev']);
      }
      if (isset($extension_package['minimum-stability'])) {
        $properties['minimum-stability'][] = $extension_package['minimum-stability'];
      }
      if (isset($extension_package['prefer-stable'])) {
        $properties['prefer-stable'][] = $extension_package['prefer-stable'];
      }
      if (isset($extension_package['repositories'])) {
        $properties['repositories'] = array_merge($extension_package['repositories'], $properties['repositories']);
      }
    }

    $root_package = [
      'name' => 'drupal/drupal',
      'type' => 'project',
      'license' => 'GPL-2.0+',
      'require' => $this->filterPlatformPackages($properties['require']),
      'require-dev' => $this->filterPlatformPackages($properties['require-dev']),
      'minimum-stability' => $this->resolveMinimumStabilityProperty($properties['minimum-stability']),
      'prefer-stable' => $this->resolvePreferStableProperty($properties['prefer-stable']),
      'repositories' => array_unique($properties['repositories'], SORT_REGULAR),
      'replace' => $core_package['replace'] + ['drupal/core' => 'self.version'],
      'scripts' => $core_package['scripts'],
      'autoload' => $this->rebaseAutoloadPaths($core_package['autoload']),
      'config' => [
        'preferred-install' => 'dist',
        'autoloader-suffix' => 'Drupal8',
      ],
      'extra' => [
        '_generator' => 'Generated by composer_manager on ' . date('c'),
        '_sources' => implode(', ', $sources),
      ]
    ];
    // Avoid defining an empty repositories key.
    if (empty($root_package['repositories'])) {
      unset($root_package['repositories']);
    }
    // Re-add our commands so that they work on the next run.
    $src_path = str_replace($this->root . '/', '', __DIR__);
    $root_package['autoload']['psr-4']['Drupal\\composer_manager\\Composer\\'] =  $src_path . '/Composer';
    $root_package['scripts']['post-install-cmd'] = 'Drupal\\composer_manager\\Composer\\Command::rewriteAutoload';
    $root_package['scripts']['drupal-rebuild'] = 'Drupal\\composer_manager\\Composer\\Command::rebuild';
    $root_package['scripts']['drupal-install'] = 'Drupal\\composer_manager\\Composer\\Command::install';
    $root_package['scripts']['drupal-update'] = 'Drupal\\composer_manager\\Composer\\Command::update';

    return $root_package;
  }

  /**
   * Removes platform packages from the requirements.
   *
   * Platform packages include 'php' and its various extensions ('ext-curl',
   * 'ext-intl', etc). Drupal modules have their own methods for raising the PHP
   * requirement ('php' key in $extension.info.yml) or requiring additional
   * PHP extensions (hook_requirements()).
   *
   * @param array $requirements
   *   The requirements.
   *
   * @return array
   *   The filtered requirements array.
   */
  protected function filterPlatformPackages($requirements) {
    foreach ($requirements as $package => $constraint) {
      if (strpos($package, '/') === FALSE) {
        unset($requirements[$package]);
      }
    }

    return $requirements;
  }

  /**
   * Resolves the minimum-stability property.
   *
   * @param array $properties
   *   The gathered minimum-stability properties.
   *   For example, ['stable', 'rc', 'beta'], where 'beta' would be selected
   *   since it is the lowest, satisfying the widest range of required packages.
   *
   * @return string
   *   The resolved minimum-stability property.
   */
  protected function resolveMinimumStabilityProperty(array $properties) {
    $minimum_stability = 'stable';
    foreach (array_unique($properties) as $property) {
      if ($this->stability[$property] < $this->stability[$minimum_stability]) {
        $minimum_stability = $property;
      }
    }

    return $minimum_stability;
  }

  /**
   * Resolves the prefer-stable property.
   *
   * It's preferable for this property to be set to TRUE, since that prevents
   * re-downloading of core packages when minimum-stability gets lowered.
   * Therefore, prefer-stable is resolved to FALSE only if an extension
   * package explicitly specifies FALSE.
   *
   * @param array $properties
   *   The gathered prefer-stable properties.
   *   For example, [TRUE, FALSE, TRUE], where FALSE would be selected.
   *
   * @return string
   *   The resolved prefer-stable property.
   */
  protected function resolvePreferStableProperty(array $properties) {
    return in_array(FALSE, $properties) ? FALSE : TRUE;
  }

  /**
   * Rebases the autoload paths to be relative to the root package.
   *
   * @param array $autoload
   *   The autoload property as specified by the core package.
   *
   * @return array
   *   The autoload property with each path rebased to be relative to the
   *   root package.
   */
  protected function rebaseAutoloadPaths(array $autoload) {
    foreach ($autoload as $group => $paths) {
      foreach ($paths as $index => $path) {
        if (substr($path, 0, 4) == 'lib/') {
          $autoload[$group][$index] = 'core/' . $path;
        }
        elseif (substr($path, 0, 3) == '../') {
          $autoload[$group][$index] = substr($path, 3);
        }
      }
    }

    return $autoload;
  }

}
