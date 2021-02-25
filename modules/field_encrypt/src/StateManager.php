<?php

namespace Drupal\field_encrypt;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\DynamicallyFieldableEntityStorageInterface;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Manages state for the module.
 *
 * The module state includes:
 * - the list of entity types with encrypted fields.
 * - the installation and removal of the encrypted_field_storage base field.
 * - the management of the entity definitions.
 */
class StateManager {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The entity last installed schema repository.
   *
   * @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface
   */
  protected $entitySchemaRepository;

  /**
   * The entity definition update manager.
   *
   * @var \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface
   */
  protected $entityDefinitionUpdateManager;
  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new ConfigSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   * @param \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $entity_schema_repository
   *   The entity last installed schema repository.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $entity_definition_update_manager
   *   The entity definition update manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory, StateInterface $state, EntityLastInstalledSchemaRepositoryInterface $entity_schema_repository, EntityDefinitionUpdateManagerInterface $entity_definition_update_manager, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->entitySchemaRepository = $entity_schema_repository;
    $this->entityDefinitionUpdateManager = $entity_definition_update_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Figure out which entity types are encrypted.
   */
  public function update() {
    $old_entity_types = $this->state->get('field_encrypt.entity_types', []);

    $new_entity_types = $this->getEntityTypes();
    if ($old_entity_types === $new_entity_types) {
      // No changes to make. Early return to do nothing and preserve caches.
      return;
    }

    // Get entities where we need to add a field.
    foreach (array_diff($new_entity_types, $old_entity_types) as $type) {
      $definition = static::getEncryptedFieldStorageDefinition();
      $this->entityDefinitionUpdateManager->installFieldStorageDefinition(ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME, $type, 'field_encrypt', $definition);
    }

    // We can't remove the field if there are queue items to process because if
    // there is data we'll destroy it. So merge in the old entity types.
    $this->state->set('field_encrypt.entity_types', array_merge($old_entity_types, $new_entity_types));
    // @see field_encrypt.module
    $this->moduleHandler->resetImplementations();
    // @see field_encrypt_entity_type_alter()
    $this->entityTypeManager->clearCachedDefinitions();
    $this->setEntityTypeCacheInformation($new_entity_types);
  }

  /**
   * Reacts to field_encrypt.settings:make_entities_uncacheable changes.
   *
   * @return static
   */
  public function onFieldEncryptSettingsCacheChange() {
    $this->entityTypeManager->clearCachedDefinitions();
    $this->setEntityTypeCacheInformation($this->state->get('field_encrypt.entity_types', []));
    return $this;
  }

  /**
   * Sets the last installed entity cache information correctly.
   *
   * @param string[] $entity_type_ids
   *   The entity type IDs to set the cache information for.
   *
   * @see field_encrypt_entity_type_alter()
   */
  protected function setEntityTypeCacheInformation(array $entity_type_ids) {
    $entity_types = $this->entityTypeManager->getDefinitions();

    // Types that have changed need to have their last installed definition
    // updated. We need to be careful to only change the settings we are
    // interested in.
    foreach ($entity_type_ids as $type) {
      $last_installed_definition = $this->entitySchemaRepository->getLastInstalledDefinition($type);
      $last_installed_definition
        ->set('render_cache', $entity_types[$type]->get('render_cache') ?? FALSE)
        ->set('persistent_cache', $entity_types[$type]->get('persistent_cache') ?? FALSE);
      $this->entitySchemaRepository->setLastInstalledDefinition($last_installed_definition);
    }
  }

  /**
   * Removes storage base fields if possible.
   */
  public function removeStorageFields() {
    $queue = $this->queueFactory->get('field_encrypt_update_entity_encryption');
    // We can't remove the field if there are queue items to process because if
    // there is data we'll destroy it.
    if ($queue->numberOfItems() > 0) {
      return;
    }

    $old_entity_types = $this->state->get('field_encrypt.entity_types', []);
    $new_entity_types = $this->getEntityTypes();

    if ($old_entity_types === $new_entity_types) {
      // No changes to make. Early return to do nothing and preserve caches.
      return;
    }
    $this->state->set('field_encrypt.entity_types', $new_entity_types);
    // @see field_encrypt.module
    $this->moduleHandler->resetImplementations();
    foreach (array_diff($old_entity_types, $new_entity_types) as $type) {
      $field = $this->entityDefinitionUpdateManager->getFieldStorageDefinition(ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME, $type);
      if ($field) {
        $this->entityDefinitionUpdateManager->uninstallFieldStorageDefinition($field);
      }
    }
  }

  /**
   * Gets the field definition for the blob where we store data.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The field definition for the blob where we store data.
   */
  public static function getEncryptedFieldStorageDefinition() {
    return BaseFieldDefinition::create('encrypted_field_storage')
      ->setLabel(new TranslatableMarkup('Encrypted data'))
      ->setDescription(new TranslatableMarkup('Stores data from encrypted fields.'))
      ->setInternal(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);
  }

  /**
   * Lists entity types which have encrypted fields.
   *
   * @return string[]
   *   The list of entity types with encrypted fields. Keyed by entity type ID.
   */
  protected function getEntityTypes() {
    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $storage_class = $this->entityTypeManager->createHandlerInstance($entity_type->getStorageClass(), $entity_type);
        if ($storage_class instanceof DynamicallyFieldableEntityStorageInterface) {
          $entity_type_id = $entity_type->id();
          // Check base fields.
          if ($this->entityTypeManager->getStorage('field_encrypt_entity_type')->load($entity_type_id)) {
            $entity_types[$entity_type_id] = $entity_type_id;
            continue;
          }
          // Query by filtering on the ID as this is more efficient than
          // filtering on the entity_type property directly.
          $ids = $this->entityTypeManager->getStorage('field_storage_config')->getQuery()
            ->condition('id', $entity_type_id . '.', 'STARTS_WITH')
            ->execute();
          // Fetch all fields on entity type.
          /** @var \Drupal\field\FieldStorageConfigInterface[] $field_storages */
          $field_storages = $this->entityTypeManager->getStorage('field_storage_config')->loadMultiple($ids);
          foreach ($field_storages as $storage) {
            // Check if field is encrypted.
            if ($storage->getThirdPartySetting('field_encrypt', 'encrypt', FALSE) == TRUE) {
              $entity_types[$entity_type_id] = $entity_type_id;
              continue 2;
            }
          }
        }
      }
    }
    return $entity_types;
  }

}
