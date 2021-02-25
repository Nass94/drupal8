<?php

/**
 * @file
 * Contains Drupal\lockr\Plugin\KeyProvider\LockrKeyProvider.
 */

namespace Drupal\lockr\Plugin\KeyProvider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;

use Lockr\Exception\LockrApiException;
use Lockr\Lockr;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;
use Drupal\key\Plugin\KeyProviderSettableValueInterface;

/**
 * Adds a key provider that allows a key to be stored in Lockr.
 *
 * @KeyProvider(
 *   id = "lockr",
 *   label = "Lockr",
 *   description = @Translation("The Lockr key provider stores the key in Lockr key management service."),
 *   storage_method = "lockr",
 *   key_value = {
 *     "accepted" = TRUE,
 *     "required" = TRUE
 *   }
 * )
 */
class LockrKeyProvider extends KeyProviderBase implements KeyProviderSettableValueInterface, KeyPluginFormInterface {

  /**
   * Drupal config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Lockr library client.
   *
   * @var Lockr
   */
  protected $lockr;

  /**
   * Logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new LockrKeyProvider.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param Lockr $lockr
   *   The Lockr library client.
   * @param LoggerChannelFactoryInterface $logger_factory
   *   The Drupal logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Lockr $lockr,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->configFactory = $config_factory;
    $this->secretStorage = $entity_type_manager->getStorage('lockr_secret');
    $this->lockr = $lockr;
    $this->logger = $logger_factory->get('lockr');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('lockr.lockr'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ) {
    $info = $this->lockr->getInfo();

    if (!$info) {
      $form['need_register'] = [
        '#prefix' => '<p>',
        '#markup' => $this->t('This site has not yet registered with Lockr, please <a href="@link">click here to register</a>.',
          ['@link' => Url::fromRoute('lockr.admin')->toString()]),
        '#suffix' => '</p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $key_id = $this->getLockrSecretName($key->id());
    try {
      return $this->lockr->getSecretValue($key_id);
    }
    catch (\Exception $e) {
      if ($e->getCode() === 404) {
        $key_type = $key->getKeyType();
        if ($key_type->getPluginId() === 'lockr_encryption') {
          $key_size = (int) $key_type->getConfiguration()['key_size'];
          $new_value = $this->lockr->generateKey($key_size);
          try {
            $this->setKeyValue($key, $new_value);
          }
          catch (\Exception $e) {
            $this->logException($e);
            return NULL;
          }
          return $new_value;
        }
      }
      $this->logException($e);
      return NULL;
    }
  }

  /**
   * Logs exceptions that occur during Lockr requests.
   *
   * @param \Exception $e
   *   The exception to log.
   */
  protected function logException(\Exception $e) {
    $this->logger->error(
      'Error retrieving value from Lockr [{ex_code}]: {ex_msg}',
      [
        'ex_code' => $e->getCode(),
        'ex_msg' => $e->getMessage(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setKeyValue(KeyInterface $key, $key_value) {
    $this->lockr->createSecretValue(
      $this->getLockrSecretName($key->id()),
      $key_value,
      $key->label(),
      $this->configFactory->get('lockr.settings')->get('region')
    );
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteKeyValue(KeyInterface $key) {
    $this->lockr->deleteSecretValue($key->id());
    return TRUE;
  }

  /**
   * Gets the lockr secret name for the given key ID.
   */
  public function getLockrSecretName($key_id) {
    $secrets = $this->secretStorage->loadByProperties(['key_id' => $key_id]);
    if (!$secrets) {
      return $key_id;
    }
    return reset($secrets)->id();
  }

}
