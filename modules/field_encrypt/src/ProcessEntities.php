<?php

namespace Drupal\field_encrypt;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Service class to process entities and fields for encryption.
 */
class ProcessEntities {

  /**
   * The name of the field that stores encrypted data.
   */
  const ENCRYPTED_FIELD_STORAGE_NAME = 'encrypted_field_storage';

  /**
   * This value is used in place of the real value in the database.
   */
  const ENCRYPTED_VALUE = '🔒';

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a ProcessEntities object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * Encrypts an entity's encrypted fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to encrypt.
   *
   * @see field_encrypt_entity_presave()
   * @see field_encrypt_entity_update()
   * @see field_encrypt_entity_insert()
   * @see field_encrypt_module_implements_alter()
   */
  public function encryptEntity(ContentEntityInterface $entity) {
    // Make sure there is a base field to store encrypted data.
    if (!$entity->hasField(static::ENCRYPTED_FIELD_STORAGE_NAME)) {
      return;
    }
    // Process all language variants of the entity.
    $languages = $entity->getTranslationLanguages();
    foreach ($languages as $language) {
      $translated_entity = $entity->getTranslation($language->getId());
      $field = $translated_entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME);
      // Before encrypting the entity ensure the encrypted field storage is
      // empty so that any changes to encryption settings are processed as
      // expected.
      if (!$field->isEmpty()) {
        $field->removeItem(0);
        $field->appendItem();
      }
      foreach ($this->getEncryptedFields($translated_entity) as $field) {
        $this->encryptField($translated_entity, $field);
      }

      // The entity storage handler has clever logic to ensure that configurable
      // fields are only saved if necessary. If entity->original is set we need
      // to ensure the field values are the values in the database and not the
      // unencrypted values so that they are saved if necessary. This is
      // particularly important when a previously encrypted field is set to be
      // unencrypted.
      // @see \Drupal\Core\Entity\Sql\SqlContentEntityStorage::saveToDedicatedTables()
      // @see \Drupal\Core\Entity\ContentEntityStorageBase::hasFieldValueChanged()
      if (isset($translated_entity->original) && $translated_entity->original instanceof ContentEntityInterface) {
        if ($translated_entity->original->hasTranslation($language->getId())) {
          $translated_original = $translated_entity->original->getTranslation($language->getId());
          $this->setEncryptedFieldValues($translated_original, 'getUnencryptedPlaceholderValue');
        }
      }
      // All the encrypted fields have now being processed and their values
      // moved to encrypted field storage. It's time to encrypt that field.
      $translated_entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME)[0]->encrypt();
    }
  }

  /**
   * Decrypts an entity's encrypted fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to decrypt.
   *
   * @see field_encrypt_entity_storage_load()
   */
  public function decryptEntity(ContentEntityInterface $entity) {
    // Make sure there is a base field to store encrypted data.
    if (!$entity->hasField(static::ENCRYPTED_FIELD_STORAGE_NAME)) {
      return;
    }

    // Process all language variants of the entity.
    $languages = $entity->getTranslationLanguages();
    foreach ($languages as $language) {
      $translated_entity = $entity->getTranslation($language->getId());
      $this->setEncryptedFieldValues($translated_entity);
    }
  }

  /**
   * Encrypts a field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being encrypted.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to encrypt.
   */
  protected function encryptField(ContentEntityInterface $entity, FieldItemListInterface $field) {
    $definition = $field->getFieldDefinition();
    $storage = $definition->getFieldStorageDefinition();

    $field_value = $field->getValue();
    // Get encryption settings from storage.
    if ($storage->isBaseField()) {
      $properties = $storage->getSetting('field_encrypt.properties') ?? [];
    }
    else {
      /** @var \Drupal\field\FieldStorageConfigInterface $storage */
      $properties = $storage->getThirdPartySetting('field_encrypt', 'properties', []);
    }
    // Process the field with the given encryption provider.
    foreach ($field_value as $delta => &$value) {
      // Process each of the field properties that exist.
      foreach ($properties as $property_name) {
        if (isset($value[$property_name])) {
          $value[$property_name] = $this->encryptFieldValue($entity, $field, $delta, $property_name, $value[$property_name]);
        }
      }
    }
    // Set the new value. Calling setValue() updates the entity too.
    $field->setValue($field_value);
  }

  /**
   * Sets an entity's encrypted fields to a value.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to set the values on.
   * @param string|null $method
   *   (optional) The method call to set the value. By default, this code sets
   *   the encrypted field to the decrypted value. If $method is set then it is
   *   called with the entity, the field and the property name.
   */
  protected function setEncryptedFieldValues(ContentEntityInterface $entity, string $method = NULL) {
    $storage = $entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME)->decrypted_value ?? [];

    foreach ($storage as $field_name => $decrypted_field) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $field = $entity->get($field_name);
      $field_value = $field->getValue();
      // Process each of the field properties that exist.
      foreach ($field_value as $delta => &$value) {
        if (!isset($storage[$field_name][$delta])) {
          continue;
        }
        // Process each of the field properties that exist.
        foreach ($decrypted_field[$delta] as $property_name => $decrypted_value) {
          if ($method) {
            // @see \Drupal\field_encrypt\ProcessEntities::getUnencryptedPlaceholderValue()
            $value[$property_name] = $this->$method($entity, $field, $property_name);
          }
          else {
            $value[$property_name] = $decrypted_value;
          }
        }
      }
      // Set the new value. Calling setValue() updates the entity too.
      $field->setValue($field_value);
    }
  }

  /**
   * Gets the encrypted fields from the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity with encrypted fields.
   *
   * @return iterable
   *   An iterator over the fields which are configured to be encrypted.
   */
  protected function getEncryptedFields(ContentEntityInterface $entity) {
    foreach ($entity->getFields() as $field) {
      $storage = $field->getFieldDefinition()->getFieldStorageDefinition();

      $is_base_field = $storage->isBaseField();
      // Check if the field is encrypted.
      if (
        ($is_base_field && $storage->getSetting('field_encrypt.encrypt')) ||
        (!$is_base_field && $storage->getThirdPartySetting('field_encrypt', 'encrypt', FALSE))
      ) {
        yield $field;
      }
    }
  }

  /**
   * Moves the unencrypted value to the encrypted field storage.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to process.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to process.
   * @param int $delta
   *   The field delta.
   * @param string $property_name
   *   The name of the property.
   * @param mixed $value
   *   The value to decrypt.
   *
   * @return mixed
   *   The encrypted field database value.
   */
  protected function encryptFieldValue(ContentEntityInterface $entity, FieldItemListInterface $field, int $delta, string $property_name, $value = '') {
    // Do not modify empty strings.
    if ($value === '') {
      return '';
    }

    $storage = $entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME)->decrypted_value ?? [];
    if ($this->allowEncryption($entity, $field->getName(), $delta, $property_name, $field, $value)) {
      $storage[$field->getName()][$delta][$property_name] = $value;
      $entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME)->decrypted_value = $storage;

      // Return value to store for unencrypted property.
      // We can't set this to NULL, because then the field values are not
      // saved, so we can't replace them with their unencrypted value on load.
      return $this->getUnencryptedPlaceholderValue($entity, $field, $property_name);
    }

    // If not allowed, but we still have an encrypted value remove it.
    if (isset($storage[$field->getName()][$delta][$property_name])) {
      unset($storage[$field->getName()][$delta][$property_name]);
      $entity->get(static::ENCRYPTED_FIELD_STORAGE_NAME)->decrypted_value = $storage;
    }
    return $value;
  }

  /**
   * Defines if a given field + property on an entity should be encrypted.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to encrypt fields on.
   * @param string $field_name
   *   The field name to update.
   * @param int $delta
   *   The field delta.
   * @param string $property_name
   *   The field property name.
   * @param \Drupal\Core\Field\FieldItemListInterface $field_list
   *   The field list item.
   * @param mixed $field_value
   *   The field's value.
   *
   * @return bool
   *   Whether to encrypt this field or not.
   */
  protected function allowEncryption(ContentEntityInterface $entity, string $field_name, int $delta, string $property_name, FieldItemListInterface $field_list, $field_value) {
    if ($field_value === $this->getUnencryptedPlaceholderValue($entity, $field_list, $property_name)) {
      return FALSE;
    }
    foreach ($this->moduleHandler->getImplementations('field_encrypt_allow_encryption') as $module) {
      $result = $this->moduleHandler->invoke(
        $module,
        'field_encrypt_allow_encryption',
        [$entity, $field_name, $delta, $property_name]
      );
      // If the implementation returns a FALSE boolean value, disable
      // encryption.
      if ($result === FALSE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Render a placeholder value to be stored in the unencrypted field storage.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to encrypt fields on.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to encrypt.
   * @param string $property_name
   *   The property to encrypt.
   *
   * @return mixed
   *   The unencrypted placeholder value.
   */
  protected function getUnencryptedPlaceholderValue(ContentEntityInterface $entity, FieldItemListInterface $field, string $property_name) {
    $unencrypted_storage_value = NULL;

    $property_definitions = $field->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
    $data_type = $property_definitions[$property_name]->getDataType();

    switch ($data_type) {
      case "string":
      case "email":
      case "datetime_iso8601":
      case "duration_iso8601":
      case "uri":
      case "filter_format":
        // Decimal fields are string data type, but get stored as number.
        if ($field->getFieldDefinition()->getType() == "decimal") {
          $unencrypted_storage_value = 0;
        }
        else {
          $unencrypted_storage_value = static::ENCRYPTED_VALUE;
        }
        break;

      case "integer":
      case "boolean":
      case "float":
        $unencrypted_storage_value = 0;
        break;
    }

    $context = [
      "entity" => $entity,
      "field" => $field,
      "property" => $property_name,
    ];
    $this->moduleHandler->alter('field_encrypt_unencrypted_storage_value', $unencrypted_storage_value, $context);

    return $unencrypted_storage_value;
  }

  /**
   * Sets an entity's encrypted field's cache tags appropriately.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being viewed.
   * @param array $build
   *   A renderable array representing the entity content.
   *
   * @see field_encrypt_entity_view()
   */
  public function entitySetCacheTags(ContentEntityInterface $entity, array &$build) {
    foreach ($this->getEncryptedFields($entity) as $field) {
      $build[$field->getName()]['#cache']['max-age'] = 0;
    }
  }

}
