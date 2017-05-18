<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\default_content\ScannerInterface;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Serializer;


/**
 * A service for handling import of default content.
 *
 * The importContent() method is almost duplicate of
 *   \Drupal\default_content\Importer::importContent with injected code for
 *   content update. We are waiting for better DC code structure in a future.
 */
class Importer extends \Drupal\default_content\Importer {

  /**
   * @var bool
   */
  protected $writeEnable;

  /**
   * @var \Drupal\default_content_deploy\DefaultContentDeployBase
   */
  private $dcdBase;


  /**
   * Constructs the default content deploy manager.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The link manager service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\default_content\ScannerInterface $scanner
   *   The file scanner.
   * @param string $link_domain
   *   Defines relation domain URI for entity links.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The account switcher.
   * @param \Drupal\default_content_deploy\DefaultContentDeployBase $dcdBase
   *   DefaultContentDeployBase.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, EventDispatcherInterface $event_dispatcher, ScannerInterface $scanner, $link_domain, AccountSwitcherInterface $account_switcher, DefaultContentDeployBase $dcdBase) {
    parent::__construct($serializer, $entity_type_manager, $link_manager, $event_dispatcher, $scanner, $link_domain, $account_switcher);
    $this->dcdBase = $dcdBase;
  }

  /**
   * Import data from JSON and create new entities, or update existing.
   *
   * Method is cloned from \Drupal\default_content\Importer::importContent.
   * Injected code is marked in comment. Look for text:
   * "Here is start of injected code for Entity update."
   *
   * @param bool $force_update
   *   TRUE for overwrite entities with matching ID but different UUID.
   *
   * @return array
   * @throws \Exception
   */
  public function deployContent($force_update = FALSE, $writeEnable = FALSE) {
    $this->writeEnable = $writeEnable;
    $created = [];
    $result_info = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
    ];
    $folder = $this->dcdBase->getContentFolder();

    if (file_exists($folder)) {
      $root_user = $this->entityTypeManager->getStorage('user')->load(1);
      $this->accountSwitcher->switchTo($root_user);
      $file_map = [];
      /** @var \Drupal\Core\Entity\EntityType $entity_type */
      foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface(ConfigEntityInterface::class)) {
          continue;
        }
        // Skip entities without folder for import.
        elseif (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = $this->scanner->scan($folder . '/' . $entity_type_id);
        // Default content uses drupal.org as domain.
        // @todo Make this use a uri like default-content:.
        $this->linkManager->setLinkDomain($this->linkDomain);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          $contents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');
          // Get the link to this entity.
          $item_uuid = $decoded['uuid'][0]['value'];

          // Throw an exception when this UUID already exists.
          if (isset($file_map[$item_uuid])) {
            // Reset link domain.
            $this->linkManager->setLinkDomain(FALSE);
            throw new \Exception(sprintf('Default content with uuid "%s" exists twice: "%s" "%s"', $item_uuid, $file_map[$item_uuid]->uri, $file->uri));
          }

          // Store the entity type with the file.
          $file->entity_type_id = $entity_type_id;
          // Store the file in the file map.
          $file_map[$item_uuid] = $file;
          // Create a vertex for the graph.
          $vertex = $this->getVertex($item_uuid);
          $this->graph[$vertex->id]['edges'] = [];
          if (empty($decoded['_embedded'])) {
            // No dependencies to resolve.
            continue;
          }
          // Here we need to resolve our dependencies:
          foreach ($decoded['_embedded'] as $embedded) {
            foreach ($embedded as $item) {
              $uuid = $item['uuid'][0]['value'];
              $edge = $this->getVertex($uuid);
              $this->graph[$vertex->id]['edges'][$edge->id] = TRUE;
            }
          }
        }
      }

      // @todo what if no dependencies?
      $sorted = $this->sortTree($this->graph);
      foreach ($sorted as $link => $details) {
        if (!empty($file_map[$link])) {

          $result_info['processed']++;

          $file = $file_map[$link];
          $entity_type_id = $file->entity_type_id;
          $class = $this->entityTypeManager->getDefinition($entity_type_id)
            ->getClass();
          $contents = $this->parseFile($file);
          /** @var \Drupal\Core\Entity\Entity $entity */
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);

          if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
            print("\n" . t("@count. @entity_type_id/id @id", [
                '@count' => $result_info['processed'],
                '@entity_type_id' => $entity_type_id,
                '@id' => $entity->id()
              ]) . "\t");
          }


          // Here is start of injected code for Entity update.
          // Test if entity (defined by UUID) already exists.
          if ($current_entity = $this->loadEntityByUuid($entity_type_id, $entity->uuid())) {
            // Yes, entity already exists.
            if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
              print(t("exists"));
            }
            // Get the last update timestamps if available.
            if (method_exists($current_entity, 'getChangedTime')) {
              /** @var \Drupal\Core\Entity\EntityChangedTrait $entity */
              $current_entity_changed_time = $current_entity->getChangedTime();
              $entity_changed_time = $entity->getChangedTime();
            }
            else {
              // We are not able to get updated time of entity, so we will force entity update.
              $current_entity_changed_time = 0;
              $entity_changed_time = 1;
            }

            //$this->printEntityTimeInfo($entity, $current_entity_changed_time, $entity_changed_time);

            // Check if destination entity is older than existing content.
            if ($current_entity_changed_time < $entity_changed_time) {
              // Update existing older entity with newer one.
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                print(" - " . t("update") . "\t");
              }
              /** @var \Drupal\Core\Entity\Entity $entity */
              $entity->{$entity->getEntityType()
                ->getKey('id')} = $current_entity->id();
              $entity->setOriginalId($current_entity->id());
              $entity->enforceIsNew(FALSE);
              try {
                /** @var Node $entity */
                $entity->setNewRevision(FALSE);
              }
              catch (\LogicException $e) {
              }
            }
            else {
              // Skip entity. No update. Newer or the same content
              // already exists.
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                print(" - " . t("skip") . "\t");
              }
              $result_info['skipped']++;
              continue;
            }
          }
          // Non-existing UUID. Test if exists Current entity by ID (not UUID).
          // If YES, then we can replace it or skip - or update user uuid and name.
          elseif ($current_entity_object = $this->loadEntityById($entity_type, $entity->id())) {
            if ($force_update) {
              // Don't recreate existing user entity, because it would be blocked
              // and without password. Only update its UUID and name.
              if ($entity_type_id == 'user') {
                if ($this->writeEnable) {
                  $this->dcdBase->updateUserEntity($entity->id(), $entity->uuid(), $entity->label());
                }
                $result_info['updated']++;
                if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                  print(t("force-update") . "\t");
                }
                // That is all. Go to next entity.
                continue;
              }
              // Another old entities must be deleted and save again with new content.
              if ($this->writeEnable) {
                $current_entity_object->delete();
              }
              $entity->enforceIsNew(TRUE);
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                print(t("delete+create") . "\t");
              }

            }
            else {
              // Protect and skip existing entity with different UUID.
              $result_info['skipped']++;
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                print(t("Ignored due to different UUID. Use drush --force-update option to replace it with imported content.") . "\n");
              }
              continue;
            }
          }
          else {
            // Imported entity is not exists - let's create new one.
            $entity->enforceIsNew(TRUE);
            if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
              print(t("new - create") . "\t");
            }

          }

          // Ensure that the entity is not owned by the anonymous user.
          if ($entity instanceof EntityOwnerInterface && empty($entity->getOwnerId())) {
            $entity->setOwner($root_user);
          }
          // Fill the saving method info for logger and report.
          $saving_method = $entity->isNew() ? 'created' : 'updated';

          if ($this->writeEnable) {
            $entity->save();
            $saved_entity_log_info = [
              '@type' => $entity->getEntityTypeId(),
              '@bundle' => $entity->bundle(),
              '@id' => $entity->id(),
              '@method' => $saving_method,
              '@file' => $file->name,
            ];
            \Drupal::logger('default_content_deploy')
              ->info(
                'Entity (type: @type/@bundle, ID: @id) @method successfully from @file',
                $saved_entity_log_info
              );
          }
          $created[$entity->uuid()] = $entity;
          $result_info[$saving_method]++;
        }
      }
      //$this->eventDispatcher->dispatch(DefaultContentEvents::IMPORT, new ImportEvent($created, $module));
      $this->accountSwitcher->switchBack();
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);

    if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
      print("\n------------\n");
    }

    return $result_info;
  }


  /**
   * Import url aliases.
   *
   * @return array
   *   Return number of imported or skipped aliases.
   */
  public function importUrlAliases() {
    $pathAliasStorage = \Drupal::service('path.alias_storage');
    $path_alias_storage = $pathAliasStorage;
    $count = 0;
    $skipped = 0;
    $file = $this->dcdBase->getContentFolder() . '/'
      . DefaultContentDeployBase::ALIAS_NAME . '/'
      . DefaultContentDeployBase::ALIAS_NAME . '.json';
    if (!file_destination($file, FILE_EXISTS_ERROR)) {
      $aliases = file_get_contents($file, TRUE);
      $path_aliases = JSON::decode($aliases);

      foreach ($path_aliases as $url => $alias) {
        if (!$path_alias_storage->aliasExists($alias['alias'], $alias['langcode'])) {
          if ($this->writeEnable) {
            $path_alias_storage->save($alias['source'], $alias['alias'], $alias['langcode']);
          }
          $count++;
        }
        else {
          $skipped++;
        }
      }
    }

    return ['imported' => $count, 'skipped' => $skipped];
  }

  /**
   * @param \Drupal\Core\Entity\Entity $entity
   * @param $current_entity_changed_time
   * @param $entity_changed_time
   */
  protected function printEntityTimeInfo(Entity $entity, $current_entity_changed_time, $entity_changed_time) {
    print("\n");
    print("\n");
    print('Label: ' . $entity->label());
    print("\n");
    print('Entity type/bundle: ' . $entity->getEntityType()
        ->getLabel() . '/' . $entity->bundle());
    print("\n");

    print('Existing Utime = ' . date('Y-m-d H:i:s', $current_entity_changed_time));
    print("\n");
    print('Imported Utime = ' . date('Y-m-d H:i:s', $entity_changed_time));
    print("\n");
  }

  /**
   * @param \Drupal\Core\Entity\Entity $entity
   */
  protected function getEntityInfo(Entity $entity) {
    $output = ('ID: ' . $entity->id());
    $output .= (' Label: ' . $entity->label());
    $output .= (' Entity type/bundle: ' . $entity->getEntityType()
        ->getLabel() . '/' . $entity->bundle());
    return $output;
  }

  /**
   * Load entity by ID.
   *
   * @param string $entity_type
   * @param int $id
   * @return \Drupal\Core\Entity\EntityInterface
   */
  protected function loadEntityById($entity_type, $id) {
    /** @var \Drupal\Core\Entity\Entity $entity */
    return $this->entityTypeManager->getStorage($entity_type)
      ->load($id);
  }

  /**
   * Load entity by UUID.
   *
   * @param string $entity_type_id
   * @param string $uuid
   *
   * @return bool|\Drupal\Core\Entity\Entity
   */
  protected function loadEntityByUuid($entity_type_id, $uuid) {
    $entityStorage = $this->entityTypeManager->getStorage($entity_type_id);
    $entities = $entityStorage->loadByProperties(['uuid' => $uuid]);
    if (!empty($entities)) {
      return reset($entities);
    }
    return FALSE;
  }

}


