<?php

namespace Drupal\field_encrypt\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerInterface;

/**
 * An interface for queue workers processed by ProcessQueueForm.
 *
 * @see \Drupal\field_encrypt\Form\ProcessQueueForm
 */
interface FieldEncryptQueueWorkerInterface extends QueueWorkerInterface {

  /**
   * Generates a batch message for an item.
   *
   * @param array $data
   *   The data that to generate the message.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Translatable markup
   */
  public function batchMessage(array $data);

}
