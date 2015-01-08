<?php

namespace Drupal\composer_manager\Composer;

use Composer\Script\Event;
use Composer\Console\Application;

/**
 * The command for 'composer drupal-update'.
 *
 * Regenerates the root package, then calls 'composer update'.
 */
class UpdateCommand {

  /**
   * Executes the command.
   */
  public static function execute(Event $event) {
    $package_manager = self::getPackageManager();
    $package_manager->rebuildRootPackage();

    // Change the requested command to 'update', and rerun composer.
    $_SERVER['argv'][1] = 'update';
    $application = new Application();
    $application->run();
  }

  /**
   * Returns a \Drupal\composer_manager\PackageManager instance.
   */
  public static function getPackageManager() {
    // The command is running inside Composer, which gives the autoloader
    // access to Drupal's classes but not the module classes.
    require __DIR__ . '/../ExtensionDiscovery.php';
    require __DIR__ . '/../JsonFile.php';
    require __DIR__ . '/../PackageManagerInterface.php';
    require __DIR__ . '/../PackageManager.php';
    require __DIR__ . '/../RootPackageBuilderInterface.php';
    require __DIR__ . '/../RootPackageBuilder.php';

    // Composer runs in core/, so the root is one directory above.
    $root = realpath(getcwd() . '/../');
    $root_package_builder = new \Drupal\composer_manager\RootPackageBuilder($root);
    $package_manager = new \Drupal\composer_manager\PackageManager($root, $root_package_builder);

    return $package_manager;
  }

}
