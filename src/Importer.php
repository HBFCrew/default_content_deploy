<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Path\AliasStorageInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\default_content\Importer as DCImporter;
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
class Importer extends DCImporter {

  /**
   * Flag for enable/disable writing operations.
   *
   * @var bool
   */
  protected $writeEnable;

  /**
   * Flag if some known file normalizer is installed.
   *
   * @var bool
   */
  protected $fileEntityEnabled;

  /**
   * DefaultContentDeployBase.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployBase
   */
  private $dcdBase;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  /**
   * The default_content_deploy logger channel.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $logger;

  /**
   * The path alias storage service.
   *
   * @var \Drupal\Core\Path\AliasStorageInterface
   */
  private $pathAliasStorage;

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
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Path\AliasStorageInterface $path_alias_storage
   *   The path alias storage service.
   */
  public function __construct(Serializer $serializer,
                              EntityTypeManagerInterface $entity_type_manager,
                              LinkManagerInterface $link_manager,
                              EventDispatcherInterface $event_dispatcher,
                              ScannerInterface $scanner,
                              $link_domain,
                              AccountSwitcherInterface $account_switcher,
                              DefaultContentDeployBase $dcdBase,
                              ModuleHandlerInterface $module_handler,
                              LoggerChannelFactoryInterface $logger_factory,
                              AliasStorageInterface $path_alias_storage) {
    parent::__construct($serializer, $entity_type_manager, $link_manager, $event_dispatcher, $scanner, $link_domain, $account_switcher);
    $this->dcdBase = $dcdBase;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger_factory->get('default_content_deploy');
    $this->pathAliasStorage = $path_alias_storage;
    $this->fileEntityEnabled = (
      $this->moduleHandler->moduleExists('file_entity') ||
      $this->moduleHandler->moduleExists('better_normalizers')
    );
  }

  /**
   * Import data from JSON and create new entities, or update existing.
   *
   * Method is cloned from \Drupal\default_content\Importer::importContent.
   * Injected code is marked in comment. Look for text:
   * "Here is start of injected code."
   *
   * @param bool $force_update
   *   TRUE for overwrite entities with matching ID but different UUID.
   * @param bool $writeEnable
   *   FALSE for read only operations, TRUE for real update/delete/create.
   *
   * @return array
   *   Array of result information.
   *
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
      'file_created' => 0,
    ];
    $folder = $this->dcdBase->getContentFolder();

    if (file_exists($folder)) {
      /** @var \Drupal\user\Entity\User $root_user */
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
          $jsonContents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($jsonContents, 'hal_json');
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
          $jsonContents = $this->parseFile($file);

          // Here is start of injected code.
          // ------------------------------
          // In case of error, is useful to know which file causes the problem.
          if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
            $message = t("@count. Loading file: @fileuri, entity type: @entity_type_id \t", [
              '@count' => $result_info['processed'],
              '@entity_type_id' => $entity_type_id,
              '@fileuri' => $file->uri,
            ]);
            print "\n" . $message . "\n";
          }
          if ($entity_type_id == 'file') {
            // Skip entity if file_entity module is not enabled.
            if (!$this->fileEntityEnabled) {
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                $message = t("File entity skipped. If you need to import files, enable the file_entity or better_normalizers module.");
                print $message;
              }
              $result_info['skipped']++;
              continue;
            }
            // Get Entity data from JSON.
            $originalUri = $this->getFileUriFromJson($jsonContents);
            // Check if file is already exists.
            $fileExists = is_file($originalUri) ? TRUE : FALSE;

            /** @var \Drupal\file_entity\Entity\FileEntity $entity */
            $entity = $this->loadEntityFromJson($entity_type_id, $jsonContents);
            if (!$this->writeEnable || $this->writeEnable && $fileExists) {
              // Unwanted file has been created. Delete file and revert URI.
              file_unmanaged_delete($entity->getFileUri());
              $entity->setFileUri($originalUri);
            }
            // Check situation that file entity exists, but file
            // has been deleted. Inform user about action to do.
            if (!$fileExists) {
              $result_info['file_created']++;
            }
          }
          else {
            // All entities except File.
            /** @var \Drupal\Core\Entity\Entity $entity */
            $entity = $this->loadEntityFromJson($entity_type_id, $jsonContents);
          }
          if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
            $message = t("@entity_type_id/id @id",
              [
                '@entity_type_id' => $entity_type_id,
                '@id' => $entity->id(),
              ]);
            print "\t" . $message . "\t\t";
          }

          // Test if entity (defined by UUID) already exists.
          $entityUuid = $entity->uuid();
          if ($current_entity = $this->loadEntityByUuid($entity_type_id, $entityUuid)) {
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
              // We are not able to get updated time of entity,
              // so we will force entity update.
              $current_entity_changed_time = 0;
              $entity_changed_time = 1;
            }

            // Check if destination entity is older than existing content.
            // Always skip users, because update user
            // caused blocked user without password.
            if ($entity_type_id != 'user' && $current_entity_changed_time < $entity_changed_time) {
              // Update existing older entity with newer one.
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                $message = t("update");
                print(" - $message \t");
              }
              /** @var \Drupal\Core\Entity\Entity $entity */
              $entity->{$entity->getEntityType()
                ->getKey('id')} = $current_entity->id();
              $entity->setOriginalId($current_entity->id());
              $entity->enforceIsNew(FALSE);
              try {
                /** @var \Drupal\node\Entity\Node $entity */
                $entity->setNewRevision(FALSE);
              }
              catch (\LogicException $e) {
              }
            }
            else {
              // Skip entity. No update. Newer or the same content
              // already exists.
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                $message = t("skip");
                print(" - $message \t");
              }
              $result_info['skipped']++;
              continue;
            }
          }
          // Non-existing UUID. Test if exists Current entity by ID (not UUID).
          // If YES, then we can replace it or skip
          // - or update only user's uuid and name.
          elseif ($current_entity_object = $this->loadEntityById($entity_type_id, $entity->id())) {
            if ($force_update) {
              // Don't recreate existing user entity, because it would be
              // blocked and without password. Only update its UUID and name.
              if ($entity_type_id == 'user') {
                if ($this->writeEnable) {
                  $this->dcdBase->updateUserEntity($entity->id(), $entity->uuid(), $entity->label());
                }
                $result_info['updated']++;
                if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                  $message = t("force-update");
                  print($message . "\t");
                }
                // That is all. Go to the next entity.
                continue;
              }
              // Another old entities must be deleted and save again
              // with the new content.
              if ($this->writeEnable) {
                $current_entity_object->delete();
              }
              $entity->enforceIsNew(TRUE);
              if (function_exists('drush_get_context') && drush_get_context('DRUSH_VERBOSE')) {
                $message = t("delete+create");
                print($message . "\t");
              }
            }
            else {
              // No force-update.
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
              $message = t("new - create");
              print($message . "\t");
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
            $this->logger->info('Entity @type/@bundle, ID: @id @method successfully', $saved_entity_log_info);
          }
          $created[$entity->uuid()] = $entity;
          $result_info[$saving_method]++;
        }
      }
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
    $count = 0;
    $skipped = 0;
    $file = $this->dcdBase->getContentFolder() . '/'
      . DefaultContentDeployBase::ALIAS_NAME . '/'
      . DefaultContentDeployBase::ALIAS_NAME . '.json';
    if (!file_destination($file, FILE_EXISTS_ERROR)) {
      $aliases = file_get_contents($file, TRUE);
      $path_aliases = Json::decode($aliases);

      foreach ($path_aliases as $alias) {
        if (!$this->pathAliasStorage->aliasExists($alias['alias'], $alias['langcode'])) {
          if ($this->writeEnable !== FALSE) {
            $this->pathAliasStorage->save($alias['source'], $alias['alias'], $alias['langcode']);
            $count++;
          }
        }
        else {
          $skipped++;
        }
      }
    }

    return ['imported' => $count, 'skipped' => $skipped];
  }

  /**
   * Get entity info.
   *
   * @param \Drupal\Core\Entity\Entity $entity
   *   Entity object.
   *
   * @return string
   *   Prepared info message.
   */
  protected function getEntityInfo(Entity $entity) {
    $output = ('ID: ' . $entity->id());
    $output .= (' Label: ' . $entity->label());
    $output .= (' Entity type/bundle: ' . $entity->getEntityType()->getLabel()
      . '/' . $entity->bundle());
    return $output;
  }

  /**
   * Load entity by ID.
   *
   * @param string $entity_type
   *   Name of entity type.
   * @param int $id
   *   ID of the entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Loaded entity.
   */
  protected function loadEntityById($entity_type, $id) {
    /** @var \Drupal\Core\Entity\Entity $entity */
    $entityStorage = $this->entityTypeManager->getStorage($entity_type);
    $entity = $entityStorage->load($id);
    return $entity;
  }

  /**
   * Load entity by UUID.
   *
   * @param string $entity_type
   *   Name of entity type.
   * @param string $uuid
   *   UUID of the entity to load.
   *
   * @return bool|\Drupal\Core\Entity\Entity
   *   Loaded entity.
   */
  protected function loadEntityByUuid($entity_type, $uuid) {
    $entityStorage = $this->entityTypeManager->getStorage($entity_type);
    $entities = $entityStorage->loadByProperties(['uuid' => $uuid]);
    if (!empty($entities)) {
      return reset($entities);
    }
    return FALSE;
  }

  /**
   * Get file URI from JSON export.
   *
   * @param string $contents
   *   JSON data.
   *
   * @return string
   *   URI.
   */
  protected function getFileUriFromJson($contents) {
    $entityData = $this->serializer->decode($contents, 'hal_json', ['request_method' => 'POST']);
    $originalUri = $entityData['uri'][0]['value'];
    return $originalUri;
  }

  /**
   * Get Drupal Entity from JSON export.
   *
   * @param string $entity_type_id
   *   EntityType ID.
   * @param string $jsonContents
   *   JSON data.
   *
   * @return \Drupal\Core\Entity\Entity
   *   Entity object.
   */
  protected function loadEntityFromJson($entity_type_id, $jsonContents) {
    $class = $this->entityTypeManager->getDefinition($entity_type_id)
      ->getClass();
    $entity = $this->serializer->deserialize($jsonContents, $class, 'hal_json', ['request_method' => 'POST']);
    return $entity;
  }

}
