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

  protected $database;

  protected $importer;

  protected $exporter;

  protected $settings;

  protected $entityTypeManager;

  public function __construct() {
    $this->database = \Drupal::database();
    $this->importer = \Drupal::service('default_content.importer');
    $this->exporter = \Drupal::service('default_content.exporter');
    $this->settings = \Drupal::service('settings');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  public function sandbox() {
    dpm('Debug start.');
  }

  function saveSingleFile($entity_type_id, $entity_id, $path) {
    /** @var \Drupal\default_content_deploy\DefaultContentDeploy $manager */
    $serializer = $this->get_serializer();
    $export = $this->exportContent($entity_type_id, $entity_id);
    $decoded = $serializer->decode($export, 'hal_json');
    $item_uuid = $decoded['uuid'][0]['value'];
    file_prepare_directory($path, FILE_CREATE_DIRECTORY);
    $file = $path . '/' . $item_uuid . '.json';
    $save = file_put_contents($file, $export);
    return $save;
  }

  public function get_serializer() {
    return $this->serializer;
  }
  /**
   * Set System site, Admin and Anonymous UUIDs and Admin's name
   * and display current values.
   */
  public function uuidSync($uuid_site, $uuid_anonymous, $uuid_admin, $admin_name) {

    // Site UUID.
    $config = \Drupal::config('system.site');
    $current_site_uuid = $config->get('uuid');
    //Tady tomu vubec nerozumim :)
    if (!empty($uuid_site)) {
      if ($current_site_uuid == $uuid_site) {
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
    if (!empty($uuid_anonymous)) {
      $current_uuid_anonymous = $this->updateUserUuid(0, $uuid_anonymous, 'Anonymous');
    }
    else {
      $current_uuid_anonymous = $this->getUuidByUid(0);
    }

    // Admin UUID.
    if (!empty($uuid_admin)) {
      $current_uuid_admin = $this->updateUserUuid(1, $uuid_admin, 'Admin');
    }
    else {
      $current_uuid_admin = $this->getUuidByUid(1);
    }

    // Admin Name.
    $current_name = $this->getUid1Name();
    if (!empty($admin_name)) {
      if ($current_name == $admin_name) {
        // No change.
        /*drush_log(t('No change: Admin\'s name is already @username.',
          ['@username' => $current_name]), 'warning');*/
      }
      else {
        // Update name.
        $current_name = $this->updateAdminName($admin_name);
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
   * @param string $username
   *
   * @return string new UUID
   */
  private function updateUserUuid($uid, $uuid, $username): string {
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
  private function getUuidByUid($uid): string {
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
  private function updateUuidByUid($uid, $uuid) {
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
  private function getUid1Name() {
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
   * @param string $admin_name
   *
   * @return string Current Admin name.
   */
  protected function updateAdminName($admin_name) {
    $this->database->update('users_field_data')
      ->fields(
        [
          'name' => $admin_name,
        ]
      )
      ->condition('uid', 1)
      ->execute();
    $current_name = $this->getUid1Name();
    // Validation
    return $current_name;
  }

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

  private function prepareContentFolder() {
    $hash_salt = $this->settings->getHashSalt();
    $path = 'public://content_' . $hash_salt;
    file_prepare_directory($path, FILE_CREATE_DIRECTORY);
  }

}
