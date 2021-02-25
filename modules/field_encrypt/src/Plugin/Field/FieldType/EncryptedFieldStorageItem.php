<?php

namespace Drupal\field_encrypt\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'encrypted_field_storage' field type.
 *
 * @FieldType(
 *   id = "encrypted_field_storage",
 *   label = @Translation("Encrypted field storage"),
 *   description = @Translation("Stores encrypted field data."),
 *   no_ui = true,
 * )
 */
class EncryptedFieldStorageItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = [];
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Encrypted data'));
    $properties['encryption_profile'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Encryption profile'));
    $properties['decrypted_value'] = DataDefinition::create('any')
      ->setLabel(new TranslatableMarkup('Decrypted data'))
      ->setComputed(TRUE)
      ->setClass('\Drupal\field_encrypt\EncryptedFieldComputedProperty');
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'big',
        ],
        'encryption_profile' => [
          'type' => 'varchar_ascii',
          'length' => 255,
        ],
      ],
    ];
  }

  /**
   * Encrypts the field item.
   */
  public function encrypt() {
    // If the decrypted value is set encrypt it an overwrite.
    if ($this->decrypted_value !== NULL) {
      // Always use the encryption profile from configuration to encrypt the
      // field. This allows encryption keys to easily be changed.
      $encryption_profile = \Drupal::config('field_encrypt.settings')->get('encryption_profile');
      $this->value = base64_encode(
        \Drupal::service('encryption')->encrypt(
          serialize($this->decrypted_value),
          $this->loadEncryptionProfile($encryption_profile)
        )
      );

      if ($this->get('encryption_profile')->getValue() !== $encryption_profile) {
        $this->encryption_profile = $encryption_profile;
      }
    }
    else {
      $this->value = NULL;
    }
    $this->decrypted_value = NULL;
  }

  /**
   * Decrypts the field item.
   */
  public function decrypt() {
    if ($this->value !== NULL) {
      // Use the field's encryption_profile if available. It should always be.
      $encryption_profile = (string) $this->get('encryption_profile')->getValue() ?: \Drupal::config('field_encrypt.settings')->get('encryption_profile');

      return unserialize(
        \Drupal::service('encryption')->decrypt(
          base64_decode($this->value),
          $this->loadEncryptionProfile($encryption_profile)
        )
      );
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // We cannot use the parent implementation as it does not consider the
    // additional 'decrypted_value' property.
    return $this->get('value')->getValue() === NULL && $this->get('decrypted_value') === NULL;
  }

  /**
   * Loads the encryption profile config entity.
   *
   * @return \Drupal\encrypt\EncryptionProfileInterface
   *   The EncryptionProfile entity.
   */
  protected function loadEncryptionProfile($encryption_profile) {
    return \Drupal::service('encrypt.encryption_profile.manager')->getEncryptionProfile($encryption_profile);
  }

}
