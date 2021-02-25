<?php

namespace Drupal\field_encrypt\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Queue Worker that updates an entity's encryption on cron run.
 *
 * This re-saves the entity causing it to use the current field encryption
 * settings. This can:
 * - encrypt fields that have become encrypted after the entity was last saved
 * - decrypt fields that no longer are set to be encrypted
 * - change the encryption profile that is used.
 *
 * @QueueWorker(
 *   id = "field_encrypt_update_entity_encryption",
 *   title = @Translation("Field encrypt: update encrption profile."),
 *   cron = {"time" = 15}
 * )
 */
class UpdateEntityEncryption extends QueueWorkerBase implements ContainerFactoryPluginInterface, FieldEncryptQueueWorkerInterface {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Creates a new UpdateEntityEncryption object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $entity_type = $this->entityTypeManager->getDefinition($data['entity_type']);
    $storage = $this->entityTypeManager->getStorage($data['entity_type']);
    if ($entity_type->isRevisionable()) {
      $entity = $storage->loadRevision($data['entity_id']);
    }
    else {
      $entity = $storage->load($data['entity_id']);
    }
    if ($entity instanceof RevisionableInterface) {
      $entity->setNewRevision(FALSE);
    }
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function batchMessage(array $data) {
    return $this->t('Updating @entity_type with ID @entity_id to use the latest field encryption settings', [
      '@entity_type' => $this->entityTypeManager->getDefinition($data['entity_type'])->getSingularLabel(),
      '@entity_id' => $data['entity_id'],
    ]);
  }

}
