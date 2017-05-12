<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\default_content\Event\DefaultContentEvents;
use Drupal\default_content\Event\ImportEvent;

/**
 * A service for handling import of default content.
 */
class DefaultContentDeployBase {

  const DELIMITER = ',';

  const ALIASNAME = 'aliases';

  protected $database;

  protected $importer;

  protected $exporter;

  protected $settings;

  protected $entityTypeManager;

  protected $serializer;

  /**
   * DefaultContentDeployBase constructor.
   */
  public function __construct() {
    $this->database = \Drupal::database();
    $this->importer = \Drupal::service('default_content.importer');
    $this->exporter = \Drupal::service('default_content.exporter');
    $this->settings = \Drupal::service('settings');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
    $this->serializer = \Drupal::service('serializer');
  }

  /**
   * Get content folder.
   *
   * Folder is automatically created on install inside files folder.
   * Or you can overide content folder in settings.php file.
   *
   * @example $config_directories['content'] = '../content';
   *
   * @return string
   *   Return path to the content folder.
   */
  protected function getContentFolder() {
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
   * Set System site, Admin and Anonymous UUIDs and Admin's name
   * and display current values.
   *
   * @param $uuidSite
   * @param $uuidAnonymous
   * @param $uuidAdmin
   * @param $adminName
   *
   * @return array
   */
  public function uuidSync($uuidSite, $uuidAnonymous, $uuidAdmin, $adminName) {

    // Site UUID.
    // @TODO Config do __construct()
    $config = \Drupal::config('system.site');
    $current_site_uuid = $config->get('uuid');
    //Tady tomu vubec nerozumim :)
    if (!empty($uuidSite)) {
      if ($current_site_uuid == $uuidSite) {
        /*drush_log(t('No change: system.site UUID is already @uuid',
          ['@uuid' => $uuid_site]), 'warning');*/
      }
      else {
        // Update Site UUID.
        /*drush_print(t('The System.site UUID is going to be changed to @uuid',
          ['@uuid' => $uuid_site]));
        drush_config_set('system.site', 'uuid', $uuid_site);*/
        $config = \Drupal::config('system.site');
        $current_site_uuid = $config->get('uuid');
        //$current_site_uuid = drush_config_get_value('system.site', 'uuid')['system.site:uuid'];
      }
    }

    // Anonymous UUID.
    if (!empty($uuidAnonymous)) {
      $current_uuid_anonymous = $this->updateUserUuid(0, $uuidAnonymous, 'Anonymous');
    }
    else {
      $current_uuid_anonymous = $this->getUuidByUid(0);
    }

    // Admin UUID.
    if (!empty($uuidAdmin)) {
      $current_uuid_admin = $this->updateUserUuid(1, $uuidAdmin, 'Admin');
    }
    else {
      $current_uuid_admin = $this->getUuidByUid(1);
    }

    // Admin Name.
    $current_name = $this->getUid1Name();
    if (!empty($adminName)) {
      if ($current_name == $adminName) {
        // No change.
        /*drush_log(t('No change: Admin\'s name is already @username.',
          ['@username' => $current_name]), 'warning');*/
      }
      else {
        // Update name.
        $current_name = $this->updateAdminName($adminName);
      }
    }

    return [
      'current_site_uuid'      => $current_site_uuid,
      'current_uuid_anonymous' => $current_uuid_anonymous,
      'current_uuid_admin'     => $current_uuid_admin,
      'current_name'           => $current_name,
    ];
  }

  /**
   * Update UUID of user
   *
   * @param int    $uid
   * @param string $uuid
   * @param string $userName
   *
   * @return string new UUID
   */
  protected function updateUserUuid($uid, $uuid, $userName): string {
    $current_uuid = $this->getUuidByUid($uid);
    if ($current_uuid == $uuid) {
      return FALSE;
    }
    else {
      // Perform update UUID for UID.
      $this->updateUuidByUid($uid, $uuid);
      // Get new UUID value.
      $current_uuid = $this->getUuidByUid($uid);
      // Verify value.
      if ($current_uuid == $uuid) {
        return TRUE;
      }
      else {
        return FALSE;
      }
    }
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
   * Update user's UUID by UID.
   *
   * @param int    $uid
   * @param string $uuid
   */
  protected function updateUuidByUid($uid, $uuid) {
    $this->database->update('users')
      ->fields(
        [
          'uuid' => $uuid,
        ]
      )
      ->condition('uid', $uid)
      ->execute();
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

  /**
   * Update Admin's name.
   *
   * @param string $adminName
   *
   * @return string Current Admin name.
   */
  protected function updateAdminName($adminName) {
    $this->database->update('users_field_data')
      ->fields(
        [
          'name' => $adminName,
        ]
      )
      ->condition('uid', 1)
      ->execute();
    $current_name = $this->getUid1Name();
    // Validation
    return $current_name;
  }

}
