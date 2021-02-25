<?php

namespace Drupal\field_encrypt\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Url;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\field_encrypt\ProcessEntities;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for updating encryption on an entity.
 */
class UpdateEncryptionProfileForm extends ConfirmFormBase {

  /**
   * The entity type.
   *
   * @var \Drupal\Core\Entity\EntityType
   */
  protected $entityType;

  /**
   * The encryption profile.
   *
   * @var \Drupal\encrypt\Entity\EncryptionProfile
   */
  protected $encryptionProfile;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * Constructs a new FieldEncryptDecryptForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The field encryption entity update queue.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueInterface $queue) {
    $this->entityTypeManager = $entity_type_manager;
    $this->queue = $queue;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('queue')->get('field_encrypt_update_entity_encryption')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'field_encrypt_update_encryption_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $default_encryption_profile = $this->entityTypeManager->getStorage('encryption_profile')->load(
      $this->config('field_encrypt.settings')->get('encryption_profile')
    );
    return $this->t(
      'Are you sure you want to update the encryption profile from %from to %to for %entity_type_plural?',
      [
        '%from' => $this->encryptionProfile->label(),
        '%to' => $default_encryption_profile->label(),
        '%entity_type_plural' => $this->entityType->getPluralLabel(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('field_encrypt.settings.entity_type');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Update encryption profile');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Existing entities will be updated through a batch process.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type = NULL, EncryptionProfile $encryption_profile = NULL) {
    $this->entityType = $this->entityTypeManager->getDefinition($entity_type);
    $this->encryptionProfile = $encryption_profile;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get entities that need updating.
    $query = $this->entityTypeManager->getStorage($this->entityType->id())->getQuery();
    $query->condition(
      ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '.encryption_profile',
      $this->encryptionProfile->id()
    );
    // Make sure to get all revisions for revisionable entities.
    if ($this->entityType->isRevisionable()) {
      $query->allRevisions();
    }
    $entity_ids = $query->execute();

    if (!empty($entity_ids)) {
      // Call the Queue API and add items for processing.
      $data = [
        'entity_type' => $this->entityType->id(),
      ];
      foreach (array_keys($entity_ids) as $entity_id) {
        $data['entity_id'] = $entity_id;
        $this->queue->createItem($data);
      }
    }
    $this->messenger()->addStatus($this->formatPlural(
      count($entity_ids),
      'Queued one %entity_type update. You should immediately <a href=":url">run this process manually</a>. Alternatively, the update will be performed automatically by cron.',
      'Queued @count @entity_type updates. You should immediately <a href=":url">run this process manually</a>. Alternatively, the updates will be performed automatically by cron.',
      [
        '@entity_type' => $this->entityType->getSingularLabel(),
        ':url' => Url::fromRoute('field_encrypt.process_queue')->toString(),
      ]
    ));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
