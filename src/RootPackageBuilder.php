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
  protected $stability = array(
    'dev' => 0,
    'alpha' => 1,
    'beta' => 2,
    'RC' => 3,
    'rc' => 3,
    'stable' => 4,
  );

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
    $requirements = $core_package['require'];
    $stability_constraints = array();
    $stability_constraints[] = isset($core_package['minimum-stability']) ? $core_package['minimum-stability'] : 'stable';
    $repositories = isset($core_package['repositories']) ? $core_package['repositories'] : array();

    foreach ($extension_packages as $extension_package) {
      if (isset($extension_package['require'])) {
        $requirements = array_merge($extension_package['require'], $requirements);
      }
      if (isset($extension_package['minimum-stability'])) {
        $stability_constraints[] = $extension_package['minimum-stability'];
      }
      if (isset($extension_package['repositories'])) {
        $repositories = array_merge($extension_package['repositories'], $repositories);
      }
    }

    $root_package = $core_package;
    $root_package['require'] = $this->filterPlatformPackages($requirements);
    $root_package['minimum-stability'] = $this->resolveStabilityConstraint($stability_constraints);
    if (!empty($repositories)) {
      $root_package['repositories'] = array_unique($repositories, SORT_REGULAR);
    }
    // Re-add our update command so that it works on the next run.
    $src_path = str_replace($this->root . '/', '', __DIR__);
    $root_package['autoload']['psr-4']['Drupal\\composer_manager\\Composer\\'] =  $src_path . '/Composer';
    $root_package['scripts'] = array(
      'drupal-update' => 'Drupal\\composer_manager\\Composer\\UpdateCommand::execute',
    );

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
   * Resolves the minimum-stability constraint.
   *
   * @param array $stability_constraints
   *   The constraints. For example, ['stable', 'rc', 'beta'], where 'beta'
   *   would be the resolved constraint since it is the lowest, therefore
   *   satisfying the widest range of required packages.
   *
   * @return string
   *   The resolved stability constraint.
   */
  protected function resolveStabilityConstraint(array $stability_constraints) {
    $minimum_stability = 'stable';
    foreach (array_unique($stability_constraints) as $stability_constraint) {
      if ($this->stability[$stability_constraint] < $this->stability[$minimum_stability]) {
        $minimum_stability = $stability_constraint;
      }
    }

    return $minimum_stability;
  }

}
