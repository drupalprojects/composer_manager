<?php

/**
 * @file
 * Contains \Drupal\composer_manager\ComposerPackages.
 */

namespace Drupal\composer_manager;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;

class ComposerPackages implements ComposerPackagesInterface {

  /**
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface.
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\composer_manager\FilesystemInterface
   */
  protected $filesystem;

  /**
   * @var \Drupal\composer_manager\ComposerManagerInterface
   */
  protected $manager;

  /**
   * The composer.lock file data parsed as a PHP array.
   *
   * @var array
   */
  private $composerLockFiledata;

  /**
   * Whether the composer.json file was written during this request.
   *
   * @var bool
   */
  protected $composerJsonWritten = FALSE;

  /**
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\composer_manager\FilesystemInterface $filesystem
   * @param \Drupal\composer_manager\ComposerManagerInterface $manager
   */
  public function __construct(LockBackendInterface $lock, ModuleHandlerInterface $module_handler, FilesystemInterface $filesystem, ComposerManagerInterface $manager) {
    $this->lock = $lock;
    $this->moduleHandler = $module_handler;
    $this->filesystem = $filesystem;
    $this->manager = $manager;
  }

  /**
   * @return \Drupal\composer_manager\FilesystemInterface
   */
  public function getFilesystem() {
    return $this->filesystem;
  }

  /**
   * @return \Drupal\composer_manager\ComposerManagerInterface
   */
  public function getManager() {
    return $this->manager;
  }

  /**
   * Returns the composer.lock file data parsed as a PHP array.
   *
   * @return array
   */
  public function getComposerLockFiledata() {
    if (!isset($this->composerLockFiledata)) {
      $this->composerLockFiledata = $this->manager->readComposerLockFile();
    }
    return $this->composerLockFiledata;
  }

  /**
   * Reads installed package versions from the composer.lock file.
   *
   * NOTE: Tried using `composer show -i`, but it didn't return the versions or
   * descriptions for some reason even though it does on the command line.
   *
   * @return array
   *   An associative array of package version information.
   *
   * @throws \RuntimeException
   */
  public function getInstalled() {
    $packages = array();

    $filedata = $this->getComposerLockFiledata();
    foreach ($filedata['packages'] as $package) {
      $packages[$package['name']] = array(
        'version' => $package['version'],
        'description' => !empty($package['description']) ? $package['description'] : '',
        'homepage' => !empty($package['homepage']) ? $package['homepage'] : '',
      );
    }

    ksort($packages);
    return $packages;
  }

  /**
   * Returns the packages, versions, and the modules that require them in the
   * composer.json files contained in contributed modules.
   *
   * @return array
   */
  public function getRequired() {
    $packages = array();

    $files = $this->getComposerJsonFiles();
    foreach ($files as $module => $composer_json) {
      $filedata = $composer_json->read();
      $filedata += array('require' => array());
      foreach ($filedata['require'] as $package_name => $version) {
        if ($this->manager->isValidPackageName($package_name)) {
          if (!isset($packages[$package_name])) {
            $packages[$package_name][$version] = array();
          }
          $packages[$package_name][$version][] = $module;
        }
      }
    }

    ksort($packages);
    return $packages;
  }

  /**
   * Returns each installed packages dependents.
   *
   * @return array
   *   An associative array of installed packages to their dependents.
   *
   * @throws \RuntimeException
   */
  public function getDependencies() {
    $packages = array();

    $filedata = $this->getComposerLockFiledata();
    foreach ($filedata['packages'] as $package) {
      if (!empty($package['require'])) {
        foreach ($package['require'] as $dependent => $version) {
          $packages[$dependent][] = $package['name'];
        }
      }
    }

    return $packages;
  }

  /**
   * Returns a list of packages that need to be installed.
   *
   * @return array
   */
  public function getInstallRequired() {
    $packages = array();

    $required = $this->getRequired();
    $installed = $this->getInstalled();
    $combined = array_unique(array_merge(array_keys($required), array_keys($installed)));

    foreach ($combined as $package_name) {
      if (!isset($installed[$package_name])) {
        $packages[] = $package_name;
      }
    }

    return $packages;
  }

  /**
   * Writes the consolidated composer.json file for all modules that require
   * third-party packages managed by Composer.
   *
   * @return int
   *
   * @throws \RuntimeException
   */
  public function writeComposerJsonFile() {
    $bytes = $this->composerJsonWritten = FALSE;

    // Ensure only one process runs at a time. 10 seconds is more than enough.
    // It is rare that a conflict will happen, and it isn't mission critical
    // that we wait for the lock to release and regenerate the file again.
    if (!$this->lock->acquire(__FUNCTION__, 10)) {
      throw new \RuntimeException('Timeout waiting for lock');
    }

    try {
      $composer_json = $this->manager->getComposerJsonFile();
      $files = $this->getComposerJsonFiles();

      $filedata = (array) $this->mergeComposerJsonFiles($files);
      $bytes = $composer_json->write($filedata);
      $this->composerJsonWritten = ($bytes !== FALSE);

      $this->lock->release(__FUNCTION__);
    }
    catch (\RuntimeException $e) {
      $this->lock->release(__FUNCTION__);
      throw $e;
    }

    return $bytes;
  }

  /**
   * Returns TRUE if the composer.json file was written in this request.
   *
   * @return bool
   *
   * @throws \RuntimeException
   */
  public function composerJsonFileWritten() {
    return $this->composerJsonWritten;
  }

  /**
   * Fetches the data in each module's composer.json file.
   *
   * @return \Drupal\composer_manager\ComposerFileInterface[]
   *
   * @throws \RuntimeException
   */
  function getComposerJsonFiles() {
    $files = array();

    $module_list = $this->moduleHandler->getModuleList();
    foreach ($module_list as $module_name => $filename) {
      $filepath = drupal_get_path('module', $module_name) . '/composer.json';
      $composer_json = new ComposerFile($filepath);
      if ($composer_json->exists()) {
        $files[$module_name] = $composer_json;
      }
    }

    return $files;
  }

  /**
   * Builds the JSON array containing the combined requirements of each module's
   * composer.json file.
   *
   * @param \Drupal\composer_manager\ComposerFileInterface[] $filedata
   *   An array composer.json file objects keyed by module.
   *
   * @return \Drupal\composer_manager\ComposerJsonMerger
   *
   * @throws \RuntimeException
   */
  public function mergeComposerJsonFiles(array $files) {

    // Merges the composer.json files.
    $merged = new ComposerJsonMerger($this);
    foreach ($files as $module => $composer_json) {
      $merged
        ->mergeProperty($composer_json, 'require')
        ->mergeProperty($composer_json, 'require-dev')
        ->mergeProperty($composer_json, 'conflict')
        ->mergeProperty($composer_json, 'replace')
        ->mergeProperty($composer_json, 'provide')
        ->mergeProperty($composer_json, 'suggest')
        ->mergeProperty($composer_json, 'repositories')
        ->mergeAutoload($composer_json, 'psr0', $module)
        ->mergeAutoload($composer_json, 'psr4', $module)
        ->mergeAutoload($composer_json, 'classmap', $module)
        ->mergeAutoload($composer_json, 'files', $module)
        ->mergeMinimumStability($composer_json)
      ;
    }

    // Replace all core packages if we are installing to a different vendor dir.
    if ($this->manager->getVendorDirectory() != DRUPAL_ROOT . '/core/vendor') {

      // Replace packages included in Drupal core.
      if (!isset($merged['replace'])) {
        $merged['replace'] = array();
      }
      $merged['replace'] += $this->manager->getCorePackages();

      // Replacing dev-master versions can cause dependency issues.
      if (strpos($merged['replace']['doctrine/annotations'], 'dev-master') === 0) {
        $merged['replace']['doctrine/annotations'] = '>=1.1.2';
      }
      if (strpos($merged['replace']['doctrine/common'], 'dev-master') === 0) {
        $merged['replace']['doctrine/common'] = '>=2.4.1';
      }
      if (strpos($merged['replace']['symfony/yaml'], 'dev-master') === 0) {
        $merged['replace']['symfony/yaml'] = '>=2.4.1';
      }
    }

    $this->moduleHandler->alter('composer_json', $merged);
    return $merged;
  }

  /**
   * Returns TRUE if at least one passed modules has a composer.json file,
   * which flags that the list of packages managed by Composer Manager have
   * changed.
   *
   * @param array $modules
   *   The list of modules being scanned for composer.json files, usually a list
   *   of modules that were installed or uninstalled.
   *
   * @return bool
   */
  public function haveChanges(array $modules) {
    foreach ($modules as $module) {
      $filepath = drupal_get_path('module', $module) . '/composer.json';
      $composer_json = new ComposerFile($filepath);
      if ($composer_json->exists()) {
        return TRUE;
      }
    }
    return FALSE;
  }
}
