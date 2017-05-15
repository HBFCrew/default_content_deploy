<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\default_content\ScannerInterface;
use Drupal\hal\LinkManager\LinkManagerInterface;
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
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, EventDispatcherInterface $event_dispatcher, ScannerInterface $scanner, $link_domain, AccountSwitcherInterface $account_switcher, DefaultContentDeployBase $dcdBase) {
    parent::__construct($serializer, $entity_type_manager, $link_manager, $event_dispatcher, $scanner, $link_domain, $account_switcher);
    $this->dcdBase = $dcdBase;
  }

  /**
   * Import data from JSON and create new entities, or update existing.
   *
   * @return array
   * @throws \Exception
   */
  public function deployContent() {
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
          $file = $file_map[$link];
          $entity_type_id = $file->entity_type_id;
          $class = $this->entityTypeManager->getDefinition($entity_type_id)
            ->getClass();
          $contents = $this->parseFile($file);
          /** @var \Drupal\Core\Entity\Entity $entity */
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);


          // Here is start of injected code for Entity update.
          // Test if entity (defined by UUID) already exists.
          // @todo Replace deprecated entityManager().
          if ($current_entity = \Drupal::entityManager()
            ->loadEntityByUuid($entity_type_id, $entity->uuid())
          ) {
            // Yes, entity already exists.
            // Get the last update timestamps if available.
            if (method_exists($current_entity, 'getChangedTime')) {
              /** @var \Drupal\Core\Entity\EntityChangedTrait $entity */
              $current_entity_changed_time = $current_entity->getChangedTime();
              $entity_changed_time = $entity->getChangedTime();
            }
            elseif (FALSE) {
              // @todo Try another method for update test, f.e compare file time.
            }
            else {
              // We are not able to get updated time of entity, so we will force entity update.
              $current_entity_changed_time = 0;
              $entity_changed_time = 1;
            }

            $this->printEntityTimeInfo($entity, $current_entity_changed_time, $entity_changed_time);

            // Check if destination entity is older than existing content.
            if ($current_entity_changed_time < $entity_changed_time) {
              // Update existing older entity with newer one.
              /** @var \Drupal\Core\Entity\Entity $entity */
              $entity->{$entity->getEntityType()
                ->getKey('id')} = $current_entity->id();
              $entity->setOriginalId($current_entity->id());
              $entity->enforceIsNew(FALSE);
              try {
                $entity->setNewRevision(FALSE);
              }
              catch (\LogicException $e) {
              }
            }
            else {
              // Skip entity. No update. Newer or the same content
              // already exists.
              $result_info['skipped']++;
              continue;
            }
          }
          // Non-existing UUID. Test if exists Current entity by ID (not UUID).
          // If YES, then we can update it or skip.
          // @todo Replace deprecated entity_load().
          elseif ($current_entity_object = entity_load($entity_type_id, $entity->id())) {
            print ('--------- exists --------- force update ------');
            $entity->enforceIsNew(FALSE);
            // Or we can protect existing entities (by ID). Drush option?
            // @todo In that case, we use continue;
            // continue;
          }
          else {
            // Imported entity is not exists - let's create new one.
            $entity->enforceIsNew(TRUE);
          }

          // Ensure that the entity is not owned by the anonymous user.
          if ($entity instanceof EntityOwnerInterface && empty($entity->getOwnerId())) {
            $entity->setOwner($root_user);
          }
          // Fill the saving method info for logger and report.
          $saving_method = $entity->isNew() ? 'created' : 'updated';

          $entity->save();
          $created[$entity->uuid()] = $entity;
          $result_info[$saving_method]++;

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
      }
      //$this->eventDispatcher->dispatch(DefaultContentEvents::IMPORT, new ImportEvent($created, $module));
      $this->accountSwitcher->switchBack();
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);

    return $result_info;
  }


  /**
   * @param $path_to_content_json
   * @todo Unused? Check usage of this method.
   */
  public function importFiles($path_to_content_json) {
    list($entity_type_id, $filename) = explode('/', $path_to_content_json);
    $p = drupal_get_path('profile', 'guts');
    $encoded_content = file_get_contents($p . '/content/' . $path_to_content_json);
    $serializer = \Drupal::service('serializer');
    $content = $serializer->decode($encoded_content, 'hal_json');
    global $base_url;
    $url = $base_url . base_path();
    $content['_links']['type']['href'] = str_replace('http://drupal.org/', $url, $content['_links']['type']['href']);
    $contents = $serializer->encode($content, 'hal_json');
    $class = 'Drupal\\' . $entity_type_id . '\Entity\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $entity_type_id)));
    $entity = $serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);
    $entity->enforceIsNew(TRUE);
    $entity->save();
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
          $path_alias_storage->save($alias['source'], $alias['alias'], $alias['langcode']);
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
    print('Entity type/bundle = ' . $entity->getEntityType()
        ->getLabel() . '/' . $entity->bundle());
    print("\n");

    print('Existing Utime = ' . date('Y-m-d H:i:s', $current_entity_changed_time));
    print("\n");
    print('Imported Utime = ' . date('Y-m-d H:i:s', $entity_changed_time));
    print("\n");
  }

}
