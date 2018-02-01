<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\default_content\Exporter;
use Psr\Log\LoggerAwareTrait;
use Robo\Robo;
use Symfony\Component\Serializer\Serializer;

/**
 * A service for handling import and export of default content.
 */
class DefaultContentDeployBase {

  use LoggerAwareTrait;

  const DELIMITER = ',';

  const ALIAS_NAME = 'aliases';

  protected $database;

  protected $importer;

  protected $exporter;

  protected $settings;

  protected $entityTypeManager;

  protected $serializer;

  /**
   * DefaultContentDeployBase constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   DB connection.
   * @param \Drupal\default_content\Exporter $exporter
   *   Exporter.
   * @param \Drupal\Core\Site\Settings $settings
   *   Settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   Serializer.
   */
  public function __construct(Connection $database,
                              Exporter $exporter,
                              Settings $settings,
                              EntityTypeManagerInterface $entityTypeManager,
                              Serializer $serializer) {
    $this->database = $database;
    $this->exporter = $exporter;
    $this->settings = $settings;
    $this->entityTypeManager = $entityTypeManager;
    $this->serializer = $serializer;
  }

  /**
   * Logger.
   *
   * @inheritdoc
   */
  public function logger() {
    if ($this->logger) {
      return $this->logger;
    }
    return Robo::logger();
  }

  /**
   * Get content folder.
   *
   * Folder is automatically created on install inside files folder.
   * Or you can override content folder in settings.php file.
   *
   * If no configuration is found, directory is created
   * automatically at 'public://content_' . $hash_salt;
   *
   * @example Recommended usage:
   *   $config['content_directory'] = '../content';
   * @example Backward compatibility usage:
   *   $config_directories['content_directory'] = '../content';
   *
   * @return string
   *   Return path to the content folder.
   */
  public function getContentFolder() {
    // Use of $config['content_directory'] is recommended.
    global $config;
    if (isset($config) && isset($config['content_directory'])) {
      return $config['content_directory'];
    }
    // Use of $config_directories['content_directory'] is not recommended,
    // because it causes unnecessary questions during drush cim or cex commands.
    // But it was (by mistake) implemented in 8.x-1.0-alpha1 and 8.x-1.0-alpha2,
    // so we have to maintain backward compatibility.
    // @todo Remove backward compatibility before beta version.
    try {
      $contentDir = config_get_config_directory('content_directory');
      $this->logger()
        ->warning(dt('Use $config["content_directory"] instead of deprecated $config_directories["content_directory"] in your settings.php.'));
      return $contentDir;
    }
    catch (\Exception $exception) {
      // The $config_directories['content_directory'] not found.
      // It is OK. $config_directories is not preferred way.
    }
    // No config found. Fall back to public:// directory.
    // Get part of hashed Drupal salt due to security reason
    // and construct content directory name.
    $hash_salt = substr(sha1($this->settings->getHashSalt()), 0, 8);
    return 'public://content_' . $hash_salt;
  }

  /**
   * Get UUID info.
   *
   * Get System site, Admin and Anonymous UUIDs and Admin's name
   * and display current values.
   *
   * @return array
   *   Array with info.
   */
  public function uuidInfo() {
    // @todo Config to __construct()?
    $config = \Drupal::config('system.site');
    $current_site_uuid = $config->get('uuid');
    $current_uuid_anonymous = $this->getUuidByUid(0);
    $current_uuid_admin = $this->getUuidByUid(1);
    $current_admin_name = $this->getAdminName();

    return [
      'current_site_uuid' => $current_site_uuid,
      'current_uuid_anonymous' => $current_uuid_anonymous,
      'current_uuid_admin' => $current_uuid_admin,
      'current_admin_name' => $current_admin_name,
    ];
  }

  /**
   * Get UUId for user by UID.
   *
   * @param int $uid
   *   User ID.
   *
   * @return string
   *   User UUID.
   */
  protected function getUuidByUid($uid) {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users')
      ->fields('users', ['uuid'])
      ->condition('uid', $uid);
    $result = $query->execute()->fetchCol('uuid');
    $uuid = reset($result);
    return $uuid;
  }

  /**
   * Get user name of Admin (user UID=1).
   *
   * @return string
   *   User name.
   */
  protected function getAdminName() {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users_field_data')
      ->fields('users_field_data', ['name'])
      ->condition('uid', 1);
    $result = $query->execute()->fetchCol('name');
    return reset($result);
  }

  /**
   * Update UUID and name of the user entity given by UID.
   *
   * @param int $uid
   *   User entity ID (UID).
   * @param string $uuid
   *   New UUID.
   * @param string $userName
   *   New name.
   */
  public function updateUserEntity($uid, $uuid, $userName) {
    $this->database->update('users')
      ->fields(
        [
          'uuid' => $uuid,
        ]
      )
      ->condition('uid', $uid)
      ->execute();

    $this->database->update('users_field_data')
      ->fields(
        [
          'name' => $userName,
        ]
      )
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Deletes all files and directories in the specified filepath recursively.
   *
   * @param string $path
   *   Directory. Its content will be deleted.
   * @param bool $deleteDir
   *   TRUE if you need to delete also directory in $path.
   *   FALSE if you need to delete only content of dir and its subdirs.
   *
   * @return bool
   *   FALSE if errors.
   */
  protected function deleteDirectoryContentRecursively($path,
                                                       $deleteDir = FALSE) {
    if (is_dir($path)) {
      $dir = dir($path);
      while (($entry = $dir->read()) !== FALSE) {
        if ($entry == '.' || $entry == '..') {
          continue;
        }
        $entry_path = $path . '/' . $entry;
        $this->deleteDirectoryContentRecursively($entry_path, TRUE);
      }
      $dir->close();

      if ($deleteDir) {
        \Drupal::service('file_system')->rmdir($path);
      }
      return TRUE;
    }
    return file_unmanaged_delete($path);
  }

}
