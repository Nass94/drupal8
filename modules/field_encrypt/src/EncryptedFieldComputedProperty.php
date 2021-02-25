<?php

namespace Drupal\field_encrypt;

use Drupal\Core\TypedData\TypedData;

/**
 * Decrypts the field on demand.
 */
class EncryptedFieldComputedProperty extends TypedData {

  /**
   * The decrypted data.
   *
   * @var array
   */
  protected $decryptedData = NULL;

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    if ($this->decryptedData === NULL) {
      /** @var \Drupal\field_encrypt\Plugin\Field\FieldType\EncryptedFieldStorageItem $item */
      $item = $this->getParent();
      $this->decryptedData = $item->decrypt();
    }
    return $this->decryptedData;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE) {
    $this->decryptedData = $value;
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
