<?php

/**
 * @file
 * Contains \Drupal\composer_manager\PackageManager.
 */

namespace Drupal\composer_manager;

/**
 * Manages composer packages.
 */
class PackageManager implements PackageManagerInterface {

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * The root package builder.
   *
   * @var \Drupal\composer_manager\RootPackageBuilderInterface
   */
  protected $rootPackageBuilder;

  /**
   * A cache of loaded packages.
   *
   * @var array
   */
  protected $packages = array();

  /**
   * Constructs a PackageManager object.
   *
   * @param string $root
   * @param \Drupal\composer_manager\RootPackageBuilderInterface $rootPackageBuilder
   */
  public function __construct($root, RootPackageBuilderInterface $rootPackageBuilder) {
    $this->root = $root;
    $this->rootPackageBuilder = $rootPackageBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public function getCorePackage() {
    if (!isset($this->packages['core'])) {
      $this->packages['core'] = JsonFile::read($this->root . '/composer.core.json');
    }

    return $this->packages['core'];
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionPackages() {
    if (!isset($this->packages['extension'])) {
      // @todo Do we want to scan themes as well?
      $listing = new ExtensionDiscovery($this->root);
      $extensions = $listing->scan('module');

      $this->packages['extension'] = array();
      foreach ($extensions as $extension_name => $extension) {
        $filename = $extension->getPath() . '/composer.json';
        if (is_readable($filename)) {
          $this->packages['extension'][$extension_name] = JsonFile::read($filename);
        }
      }
    }

    return $this->packages['extension'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredPackages() {
    if (!isset($this->packages['required'])) {
      // The root package on disk might not be up to date, build a new one.
      $core_package = $this->getCorePackage();
      $extension_packages = $this->getExtensionPackages();
      $root_package = $this->rootPackageBuilder->build($core_package, $extension_packages);

      $packages = array();
      foreach ($root_package['require'] as $package_name => $constraint) {
        $packages[$package_name] = array(
          'constraint' => $constraint,
        );
      }

      $installed_packages = JsonFile::read($this->root . '/core/vendor/composer/installed.json');
      foreach ($installed_packages as $package) {
        $package_name = $package['name'];
        if (!isset($packages[$package_name])) {
          // The installed package is no longer required, and will be removed
          // in the next composer update. Add it in order to inform the end-user.
          $packages[$package_name] = array(
            'constraint' => '',
          );
        }

        // Add additional information available only for installed packages.
        $packages[$package_name] += array(
          'description' => !empty($package['description']) ? $package['description'] : '',
          'homepage' => !empty($package['homepage']) ? $package['homepage'] : '',
          'require' => !empty($package['require']) ? $package['require'] : array(),
          'version' => $package['version'],
        );
        if ($package['version'] == 'dev-master') {
          $packages[$package_name]['version'] .= '#' . $package['source']['reference'];
        }
      }

      // Process and cache the package list.
      $this->packages['required'] = $this->processRequiredPackages($packages);
    }

    return $this->packages['required'];
  }

  /**
   * Formats and sorts the provided list of packages.
   *
   * @param array $packages
   *   The packages to process.
   *
   * @return array
   *   The processed packages.
   */
  protected function processRequiredPackages(array $packages) {
    $core_package = $this->getCorePackage();
    $extension_packages = $this->getExtensionPackages();
    // Add information about dependent packages.
    foreach ($packages as $package_name => $package) {
      // Detect Drupal dependents.
      if (isset($core_package['require'][$package_name])) {
        $packages[$package_name]['required_by'] = array($core_package['name']);
      }
      else {
        foreach ($extension_packages as $extension_name => $extension_package) {
          if (isset($extension_package['require'][$package_name])) {
            $packages[$package_name]['required_by'] = array($extension_package['name']);
            break;
          }
        }
      }

      // Detect inter-package dependencies.
      foreach ($package['require'] as $dependency_name => $constraint) {
        if (isset($packages[$dependency_name])) {
          $packages[$dependency_name]['required_by'][] = $package_name;
        }
      }
    }

    foreach ($packages as $package_name => &$package) {
      // Ensure the presence of all keys.
      $package += array(
        'constraint' => '',
        'description' => '',
        'homepage' => '',
        'require' => array(),
        'required_by' => array(),
        'version' => '',
      );
      // Sort the keys to ensure consistent results.
      ksort($package);
    }

    // Sort the packages by package name.
    ksort($packages);

    return $packages;
  }

  /**
   * {@inheritdoc}
   */
  public function needsComposerUpdate() {
    $needs_update = FALSE;
    foreach ($this->getRequiredPackages() as $package) {
      if (empty($package['version']) || empty($package['required_by'])) {
        $needs_update = TRUE;
        break;
      }
    }

    return $needs_update;
  }

  /**
   * {@inheritdoc}
   */
  public function rebuildRootPackage() {
    $core_package = $this->getCorePackage();
    $extension_packages = $this->getExtensionPackages();
    $root_package = $this->rootPackageBuilder->build($core_package, $extension_packages);
    JsonFile::write($this->root . '/composer.json', $root_package);
  }

}
