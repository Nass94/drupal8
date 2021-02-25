<?php

namespace Drupal\field_encrypt\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for removing encryption on field.
 */
class DecryptFieldForm extends ConfirmFormBase {

  /**
   * The entity type.
   *
   * @var string
   */
  protected $entityType;

  /**
   * The field name to decrypt.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * Whether the field is a base field.
   *
   * @var bool
   */
  protected $baseField;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new FieldEncryptDecryptForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'field_encrypt_decrypt_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t(
      'Are you sure you want to remove encryption for field %field on %entity_type?',
      ['%field' => $this->fieldName, '%entity_type' => $this->entityType]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('field_encrypt.field_overview');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Remove field encryption');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action removes field encryption from the specified field. Existing field data will be decrypted through a batch process.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type = NULL, $field_name = NULL, $base_field = FALSE) {
    $this->entityType = $entity_type;
    $this->fieldName = $field_name;
    $this->baseField = (bool) $base_field;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->baseField) {
      /** @var \Drupal\field_encrypt\Entity\FieldEncryptEntityType $field_encrypt_settings */
      $field_encrypt_settings = $this->entityTypeManager->getStorage('field_encrypt_entity_type')->load($this->entityType);
      $field_encrypt_settings
        ->removeBaseField($this->fieldName)
        ->save();
    }
    else {
      $storage = $this->entityTypeManager->getStorage('field_storage_config');
      $field_storage_config = $storage->load($this->entityType . '.' . $this->fieldName);
      $field_storage_config->unsetThirdPartySetting('field_encrypt', 'encrypt');
      $field_storage_config->unsetThirdPartySetting('field_encrypt', 'properties');
      $field_storage_config->save();
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
