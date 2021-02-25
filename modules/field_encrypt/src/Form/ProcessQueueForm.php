<?php

namespace Drupal\field_encrypt\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form builder for the field_encrypt process queue form.
 */
class ProcessQueueForm extends FormBase {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The QueueWorker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * Constructs a new FieldEncryptUpdateForm.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The QueueWorker manager.
   */
  public function __construct(QueueFactory $queue_factory, QueueWorkerManagerInterface $queue_manager) {
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'field_encrypt_update_entity_encryption';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $entity_update_count = $this->queueFactory->get('field_encrypt_update_entity_encryption')->numberOfItems();

    $form['status'] = [
      '#type' => 'item',
      '#markup' => $this->formatPlural($entity_update_count, 'There is one entity queued for updating to use the latest field encryption settings.', 'There are @count entities queued for updating to use the latest field encryption settings.'),
      '#suffix' => '<p>',
      '#prefix' => '</p>',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process updates'),
      '#access' => $entity_update_count > 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $batch = new BatchBuilder();
    $batch
      ->setTitle($this->t('Field encryption updates'))
      ->addOperation(
        [static::class, 'processUpdates'],
        ['field_encrypt_update_entity_encryption']
      )
      ->setFinishCallback([static::class, 'finishBatch']);
    batch_set($batch->toArray());
  }

  /**
   * Processes field updates.
   *
   * @param string $queue_id
   *   The ID of the queue to process.
   * @param array $context
   *   The batch API context.
   */
  public static function processUpdates($queue_id, array &$context) {
    $batch_size = \Drupal::config('field_encrypt.settings')->get('batch_size');

    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::service('queue')->get($queue_id);
    $num_items = $queue->numberOfItems();

    /** @var \Drupal\field_encrypt\Plugin\QueueWorker\FieldEncryptQueueWorkerInterface $queue_worker */
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance($queue_id);

    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $num_items;
    }

    // Process entities in groups.
    for ($i = 1; $i <= $batch_size && $i <= $num_items; $i++) {
      if ($item = $queue->claimItem()) {
        try {
          $queue_worker->processItem($item->data);
          $queue->deleteItem($item);

          $context['results']['items'][] = $item->data['entity_id'];
          $context['message'] = $queue_worker->batchMessage($item->data);
          $context['sandbox']['progress']++;
        }
        catch (SuspendQueueException $e) {
          $queue->releaseItem($item);
        }
        catch (\Exception $e) {
          watchdog_exception('field_encrypt', $e);
          if (!isset($context['results']['errors'])) {
            $context['results']['errors'] = [];
          }
          $context['results']['errors'][] = $e->getMessage();
        }
      }
      else {
        $context['finished'] = 1;
        $context['results']['errors'][] = t('Cannot claim an item - unable to process the batch');
        return;
      }
    }

    // Inform the batch engine that we are not finished,
    // and provide an estimation of the completion level we reached.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Finish batch encryption updates of fields.
   *
   * @param bool $success
   *   A boolean indicating whether the batch has completed successfully.
   * @param array $results
   *   The value set in $context['results'] by callback_batch_operation().
   */
  public static function finishBatch($success, array $results) {
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        \Drupal::messenger()->addError($error);
      }
    }

    if ($success && isset($results['items'])) {
      \Drupal::service('field_encrypt.state_manager')->removeStorageFields();
      $message = \Drupal::translation()->formatPlural(count($results['items']), 'One entity updated.', '@count entities updated.');
      \Drupal::messenger()->addMessage($message);
    }
  }

}
