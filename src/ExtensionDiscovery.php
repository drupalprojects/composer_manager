<?php

/**
 * @file
 * Contains \Drupal\composer_manager\ExtensionDiscovery.
 */

namespace Drupal\composer_manager;

use Drupal\Core\Extension\ExtensionDiscovery as BaseExtensionDiscovery;

/**
 * Discovers available extensions in the filesystem.
 */
class ExtensionDiscovery extends BaseExtensionDiscovery {

  /**
   * Overrides BaseExtensionDiscovery::scan().
   *
   * Compared to the parent method:
   * - doesn't scan core/ because composer_manager doesn't need to care about
   *   core extensions (core already ships with their dependencies).
   * - scans all (non-core) profiles for extensions.
   * - scans all sites (to accomodate the poor souls still using multisite).
   *
   * @todo Clean up after #2401607 lands.
   */
  public function scan($type, $include_tests = NULL) {
    if ($type == 'profile') {
      $this->profileDirectories = array();
    }
    else {
      // Get all non-core profiles.
      $profiles = $this->scan('profile');
      $this->profileDirectories = array_map(function ($profile) {
        return $profile->getPath();
      }, $profiles);
    }

    $searchdirs[static::ORIGIN_SITES_ALL] = 'sites/all';
    $searchdirs[static::ORIGIN_ROOT] = '';
    // Add all site directories, so that in a multisite environment each site
    // gets the necessary dependencies.
    foreach ($this->getSiteDirectories() as $index => $siteDirectory) {
      // The indexes are used as weights, so start at 10 to avoid conflicting
      // with the ones defined in the constants (ORIGIN_CORE, etc).
      $index = 10 + $index;
      $searchdirs[$index] = 'sites/' . $siteDirectory;
    }

    // We don't care about tests.
    $include_tests = FALSE;

    // From this point on the method is the same as the parent.
    $files = array();
    foreach ($searchdirs as $dir) {
      // Discover all extensions in the directory, unless we did already.
      if (!isset(static::$files[$dir][$include_tests])) {
        static::$files[$dir][$include_tests] = $this->scanDirectory($dir, $include_tests);
      }
      // Only return extensions of the requested type.
      if (isset(static::$files[$dir][$include_tests][$type])) {
        $files += static::$files[$dir][$include_tests][$type];
      }
    }

    // Sort the discovered extensions by their originating directories and,
    // if applicable, filter out extensions that do not belong to the current
    // installation profiles.
    $origin_weights = array_flip($searchdirs);
    $origins = array();
    $profiles = array();
    foreach ($files as $key => $file) {
      // If the extension does not belong to a profile, just apply the weight
      // of the originating directory.
      if (strpos($file->subpath, 'profiles') !== 0) {
        $origins[$key] = $origin_weights[$file->origin];
        $profiles[$key] = NULL;
      }
      // If the extension belongs to a profile but no profile directories are
      // defined, then we are scanning for installation profiles themselves.
      // In this case, profiles are sorted by origin only.
      elseif (empty($this->profileDirectories)) {
        $origins[$key] = static::ORIGIN_PROFILE;
        $profiles[$key] = NULL;
      }
      else {
        // Apply the weight of the originating profile directory.
        foreach ($this->profileDirectories as $weight => $profile_path) {
          if (strpos($file->getPath(), $profile_path) === 0) {
            $origins[$key] = static::ORIGIN_PROFILE;
            $profiles[$key] = $weight;
            continue 2;
          }
        }
        // If we end up here, then the extension does not belong to any of the
        // current installation profile directories, so remove it.
        unset($files[$key]);
      }
    }
    // Now sort the extensions by origin and installation profile(s).
    // The result of this multisort can be depicted like the following matrix,
    // whereas the first integer is the weight of the originating directory and
    // the second is the weight of the originating installation profile:
    // 0   core/modules/node/node.module
    // 1 0 profiles/parent_profile/modules/parent_module/parent_module.module
    // 1 1 core/profiles/testing/modules/compatible_test/compatible_test.module
    // 2   sites/all/modules/common/common.module
    // 3   modules/devel/devel.module
    // 4   sites/default/modules/custom/custom.module
    array_multisort($origins, SORT_ASC, $profiles, SORT_ASC, $files);

    // Process and return the sorted and filtered list of extensions keyed by
    // extension name.
    return $this->process($files);
  }

  /**
   * Returns an array of all site directories.
   *
   * @return array
   *   An array of site directories. For example: ['default', 'test.site.com'].
   *   Doesn't include the 'all' directory since it doesn't represent a site.
   */
  protected function getSiteDirectories() {
    $site_directories = scandir($this->root . '/sites');
    $site_directories = array_filter($site_directories, function ($site_directory) {
      $is_directory = is_dir($this->root . '/sites/' . $site_directory);
      $not_hidden = substr($site_directory, 0, 1) != '.';
      $not_all = $site_directory != 'all';

      return $is_directory && $not_hidden && $not_all;
    });

    return $site_directories;
  }

}
