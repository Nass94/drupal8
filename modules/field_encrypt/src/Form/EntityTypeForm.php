<?php

namespace Drupal\field_encrypt\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field_encrypt\ProcessEntities;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures field encryption on the entity type level.
 *
 * @see \Drupal\field_encrypt\Entity\FieldEncryptEntityType
 */
class EntityTypeForm extends FormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * FieldEncryptDecryptForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'field_encrypt_entity_type_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    if (empty($entity_type_id)) {
      $entity_type_id = NULL;
    }

    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element) {
      $entity_type_id = $form_state->getUserInput()['entity_type'];
    }
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type) {
      // Only content entity types support encryption.
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $options[$entity_type->id()] = $entity_type->getLabel();
      }
    }
    // Sort the entity types to make it easier to find the entity type to
    // configure.
    natsort($options);

    $form['entity_type'] = [
      '#title' => $this->t('Select a entity type to configure'),
      '#type' => 'select',
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $entity_type_id,
      '#ajax' => [
        'callback' => '::updateBaseFields',
        'wrapper' => 'js-edit-base-fields',
      ],
      // Disable this field when not using javascript and the entity type is
      // set.
      '#disabled' => !empty($entity_type_id) && !$triggering_element,
    ];

    $default_value = [];
    $possible_fields = [];
    if ($entity_type_id) {
      /** @var \Drupal\field_encrypt\Entity\FieldEncryptEntityType $field_encrypt_settings */
      $field_encrypt_settings = $this->entityTypeManager->getStorage('field_encrypt_entity_type')->load($entity_type_id);
      if ($field_encrypt_settings) {
        $default_value = array_keys($field_encrypt_settings->getBaseFields());
        $default_value = array_combine($default_value, $default_value);
      }
      $possible_fields = $this->getBaseFields($entity_type_id);
    }
    $form['entity_type_container'] = [
      '#type' => 'container',
      '#prefix' => '<div id="js-edit-base-fields">',
      '#suffix' => '</div>',
    ];

    $form['entity_type_container']['encryption_profiles'] = $this->getEncryptionProfilesTable($entity_type_id);

    $form['entity_type_container']['base_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $entity_type_id ? $this->t('Base fields to encrypt') : '',
      '#default_value' => $default_value,
      '#value' => $default_value,
      '#options' => $possible_fields,
      '#description' => $entity_type_id ? $this->t('Note that encrypted fields can not be filtered on in SQL queries or joined against.') : '',
    ];
    $form['entity_type_container']['base_field_properties'] = $this->getPropertiesForm($possible_fields, $entity_type_id);
    $form['entity_type_container']['base_field_properties']['#tree'] = TRUE;

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    // By default, render the form using system-config-form.html.twig.
    $form['#theme'] = 'system_config_form';

    return $form;
  }

  /**
   * Creates properties checkboxes for each base field.
   *
   * @param array $base_fields
   *   A array of base fields labels that can be encrypted. Keyed by base field
   *   name.
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   The form array containing the checkboxes.
   */
  protected function getPropertiesForm(array $base_fields, $entity_type_id) {
    // Early return if there is nought to do.
    if (!$entity_type_id || empty($base_fields)) {
      return [];
    }

    // Get information required to build a property field for each base field.
    $form = [];
    $base_field_definitions = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
    /** @var \Drupal\field_encrypt\Entity\FieldEncryptEntityType $field_encrypt_settings */
    $field_encrypt_settings = $this->entityTypeManager->getStorage('field_encrypt_entity_type')->load($entity_type_id);
    $default_values = $field_encrypt_settings ? $field_encrypt_settings->getBaseFields() : [];
    $default_properties = $this->config('field_encrypt.settings')->get('default_properties');

    foreach ($base_fields as $base_field => $base_field_label) {
      $properties = [];
      $definitions = $base_field_definitions[$base_field]->getPropertyDefinitions();
      $field_type = $base_field_definitions[$base_field]->getType();
      foreach ($definitions as $property => $definition) {
        $properties[$property] = $definition->getLabel();
      }

      $field_encrypt_default = isset($default_properties[$field_type]) ? $default_properties[$field_type] : [];
      $form[$base_field] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('@label properties', ['@label' => $base_field_label]),
        '#description' => $this->t('Specify the base field properties to encrypt. If none are selected the base field will not be encrypted.'),
        '#options' => $properties,
        '#default_value' => $default_values[$base_field] ?? $field_encrypt_default,
        '#states' => [
          'visible' => [
            ':input[name="base_fields[' . $base_field . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];

    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_type_id = $form_state->getValue('entity_type');
    if ($form['entity_type']['#default_value'] === $form_state->getValue('entity_type')) {
      /** @var \Drupal\field_encrypt\Entity\FieldEncryptEntityType $field_encrypt_settings */
      $field_encrypt_settings = $this->entityTypeManager->getStorage('field_encrypt_entity_type')->load($entity_type_id);
      if (!$field_encrypt_settings) {
        $field_encrypt_settings = $this->entityTypeManager->getStorage('field_encrypt_entity_type')->create([
          'id' => $entity_type_id,

        ]);
      }
      $base_fields = [];
      // @todo Ajax results in form not having the correct values.
      $selected_base_fields = array_filter($form_state->getUserInput()['base_fields']);
      foreach ($selected_base_fields as $base_field) {
        if ($form_state->hasValue(['base_field_properties', $base_field])) {
          $selected_base_field_properties = array_filter($form_state->getUserInput()['base_field_properties'][$base_field]);
          // Remove anything not that's change in user input.
          $base_fields[$base_field] = array_values(array_intersect_key(
            $selected_base_field_properties,
            $form_state->getValue(['base_field_properties', $base_field])
          ));
          if (empty($base_fields[$base_field])) {
            unset($base_fields[$base_field]);
          }
        }
      }
      if (empty($base_fields)) {
        $field_encrypt_settings->delete();
      }
      else {
        $field_encrypt_settings
          ->setBaseFields($base_fields)
          ->save();
      }
      $this->messenger()->addStatus($this->t('Updated encryption settings for %entity_type base fields.', ['%entity_type' => $this->entityTypeManager->getDefinition($entity_type_id)->getLabel()]));
      $form_state->setRedirect('field_encrypt.settings.entity_type');
    }
    elseif ($entity_type_id) {
      // Handle the non-javascript case and redirect to the form for the entity
      // type.
      $form_state->setRedirect('field_encrypt.settings.entity_type', ['entity_type_id' => $entity_type_id]);
    }
  }

  /**
   * Handles AJAX callback for updating the base fields.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The update base_fields checkboxes.
   */
  public function updateBaseFields(array $form, FormStateInterface $form_state) {
    return $form['entity_type_container'];
  }

  /**
   * Gets a list of base fields that can be encrypted for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string[]|\Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   A array of base fields labels that can be encrypted. Keyed by base field
   *   name.
   */
  protected function getBaseFields($entity_type_id) {
    $possible_fields = [];
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    $excluded_fields = ['id', 'revision', 'bundle', 'langcode', 'uuid'];
    $excluded_fields = array_filter(array_map(function ($key) use ($entity_type) {
      return $entity_type->getKey($key);
    }, $excluded_fields));
    // Can't encrypt the encrypted field.
    $excluded_fields[] = ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME;
    foreach ($this->entityFieldManager->getBaseFieldDefinitions($entity_type_id) as $base_field) {
      if (!in_array($base_field->getName(), $excluded_fields, TRUE)) {
        $possible_fields[$base_field->getName()] = $base_field->getLabel();
      }
    }
    return $possible_fields;
  }

  /**
   * Creates a table of encryption profiles in use.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   A render array of encryption profiles in use.
   */
  protected function getEncryptionProfilesTable($entity_type_id) {
    $form = [];
    $rows = [];
    $results = [];
    $base_fields = $entity_type_id ? $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id) : [];
    $profile_storage = $this->entityTypeManager->getStorage('encryption_profile');
    $count_alias = 'count';
    $default_encryption_profile = $this->config('field_encrypt.settings')->get('encryption_profile');

    if ($entity_type_id && isset($base_fields[ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME])) {
      $query = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->getAggregateQuery()
        ->aggregate(ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '.encryption_profile', 'COUNT', NULL, $count_alias)
        ->groupBy(ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '.encryption_profile');

      if ($this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable()) {
        $query->allRevisions();
      }
      $results = $query->execute();
    }

    foreach ($results as $result) {
      $row = [];
      $encryption_profile = $profile_storage->load($result[ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__encryption_profile']);
      $row[] = $encryption_profile->label();
      $row[] = $result[$count_alias];
      $operations = [];
      if ($encryption_profile->id() !== $default_encryption_profile) {
        $operations = [
          '#type' => 'operations',
          '#links' => [
            'update_encryption_profile' => [
              'title' => $this->t('Update encryption profile'),
              'url' => Url::fromRoute('field_encrypt.update_encryption_profile_confirm', [
                'entity_type' => $entity_type_id,
                'encryption_profile' => $encryption_profile->id(),
              ]),
            ],
          ],
        ];
      }
      $row[]['data'] = $operations;
      $rows[] = $row;
    }

    $form['encryption_profiles'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['encryption-profiles']],
      '#header' => [
        $this->t('Encryption profile'),
        $this->t('Usages'),
        $this->t('Operations'),
      ],
      '#title' => 'Encryption profiles',
      '#rows' => $rows,
      '#access' => !empty($rows),
      '#description' => $this->t('If the field encryption profile is changed entities can be updated to use the new encryption profile.'),
    ];

    return $form;
  }

}
