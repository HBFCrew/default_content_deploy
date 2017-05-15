<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Site\Settings;
use Drupal\default_content\Exporter;
use Symfony\Component\Serializer\Serializer;

/**
 * A service for handling import of default content.
 */
class DefaultContentDeployBase {

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
   * @param \Drupal\default_content\Exporter $exporter
   * @param \Drupal\Core\Site\Settings $settings
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   * @param \Symfony\Component\Serializer\Serializer $serializer
   */
  public function __construct(Connection $database, Exporter $exporter, Settings $settings, EntityTypeManager $entityTypeManager, Serializer $serializer) {
    $this->database = $database;
    $this->exporter = $exporter;
    $this->settings = $settings;
    $this->entityTypeManager = $entityTypeManager;
    $this->serializer = $serializer;
  }

  /**
   * Get content folder.
   *
   * Folder is automatically created on install inside files folder.
   * Or you can override content folder in settings.php file.
   *
   * @example $config_directories['content'] = '../content';
   *
   * @return string
   *   Return path to the content folder.
   */
  public function getContentFolder() {
    global $config_directories;
    if (isset($config_directories) && isset($config_directories['content'])) {
      return $config_directories['content'];
    }
    else {
      $hash_salt = $this->settings->getHashSalt();
      return 'public://content_' . $hash_salt;
    }
  }


  /**
   * Get System site, Admin and Anonymous UUIDs and Admin's name
   * and display current values.
   *
   * @return array
   */
  public function uuidInfo() {
    // Site UUID.
    // @TODO Config do __construct()
    $config = \Drupal::config('system.site');
    $current_site_uuid = $config->get('uuid');
    $current_uuid_anonymous = $this->getUuidByUid(0);
    $current_uuid_admin = $this->getUuidByUid(1);
    $current_admin_name = $this->getUid1Name();

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
   * @param $uid
   *
   * @return string
   */
  protected function getUuidByUid($uid): string {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users')
      ->fields('users', ['uuid'])
      ->condition('uid', $uid);
    $result = $query->execute()->fetchCol('uuid');
    $current_uuid_anonymous = reset($result);
    return $current_uuid_anonymous;
  }

  /**
   * Get name of Admin user UID=1.
   *
   * @return string Name
   */
  protected function getUid1Name() {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users_field_data')
      ->fields('users_field_data', ['name'])
      ->condition('uid', 1);
    $result = $query->execute()->fetchCol('name');
    return reset($result);
  }

}
