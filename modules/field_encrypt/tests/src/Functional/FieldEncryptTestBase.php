<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\encrypt\Functional\EncryptTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Base test class for field_encrypt.
 */
abstract class FieldEncryptTestBase extends EncryptTestBase {

  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'field',
    'field_ui',
    'text',
    'locale',
    'content_translation',
    'key',
    'encrypt',
    'encrypt_test',
    'field_encrypt',
  ];

  /**
   * The page node type.
   *
   * @var \Drupal\node\NodeTypeInterface
   */
  protected $nodeType;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A test node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $testNode;

  /**
   * {@inheritdoc}
   *
   * @TODO: Simplify setUp() by extending EncryptTestBase when https://www.drupal.org/node/2692387 lands.
   */
  protected function setUp() {
    parent::setUp();

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer encrypt',
      'administer keys',
      'administer field encryption',
    ], NULL, TRUE);
    $this->drupalLogin($this->adminUser);

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create content type to test.
    $this->nodeType = $this->drupalCreateContentType([
      'type' => 'page',
      'name' => 'Basic page',
    ]);

    // Create test fields.
    $single_field_storage = FieldStorageConfig::create([
      'field_name' => 'field_test_single',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'cardinality' => 1,
    ]);
    $single_field_storage->save();
    $single_field = FieldConfig::create([
      'field_storage' => $single_field_storage,
      'bundle' => 'page',
      'label' => 'Single field',
    ]);
    $single_field->save();
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay('node', 'page', 'default')
      ->setComponent('field_test_single')
      ->save();
    $display_repository->getViewDisplay('node', 'page', 'default')
      ->setComponent('field_test_single', [
        'type' => 'text_default',
      ])
      ->save();

    $multi_field_storage = FieldStorageConfig::create([
      'field_name' => 'field_test_multi',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 3,
    ]);
    $multi_field_storage->save();
    $multi_field = FieldConfig::create([
      'field_storage' => $multi_field_storage,
      'bundle' => 'page',
      'label' => 'Multi field',
    ]);
    $multi_field->save();
    $display_repository->getFormDisplay('node', 'page', 'default')
      ->setComponent('field_test_multi')
      ->save();
    $display_repository->getViewDisplay('node', 'page', 'default')
      ->setComponent('field_test_multi', [
        'type' => 'string',
      ])
      ->save();

    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_1')
      ->save();
  }

  /**
   * Creates a test node.
   */
  protected function createTestNode() {
    $this->testNode = $this->createNode([
      'field_test_single' => [
        [
          'value' => "Lorem ipsum dolor sit amet.",
          'summary' => "Lorem ipsum",
          'format' => filter_default_format(),
        ],
      ],
      'field_test_multi' => [
        ['value' => "one"],
        ['value' => "two"],
        ['value' => "three"],
      ],
    ]);
  }

  /**
   * Set up storage settings for test fields.
   *
   * @param bool $encryption
   *   Whether or not the fields should be encrypted. Defaults to TRUE.
   */
  protected function setFieldStorageSettings($encryption = TRUE) {
    // Set up storage settings for first field.
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_test_single/storage');
    // Encrypt field found.
    $this->assertSession()->fieldExists('field_encrypt[encrypt]');

    $edit = [
      'field_encrypt[encrypt]' => $encryption,
      'field_encrypt[properties][value]' => 'value',
      'field_encrypt[properties][summary]' => 'summary',
    ];
    $this->submitForm($edit, 'Save field settings');
    $this->assertSession()->pageTextContains('Updated field Single field field settings.');

    // Set up storage settings for second field.
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_test_multi/storage');
    // Encrypt field found.
    $this->assertSession()->fieldExists('field_encrypt[encrypt]');

    $edit = [
      'field_encrypt[encrypt]' => $encryption,
      'field_encrypt[properties][value]' => 'value',
    ];
    $this->submitForm($edit, 'Save field settings');
    $this->assertSession()->pageTextContains('Updated field Multi field field settings.');
    $this->rebuildAll();
  }

  /**
   * Set up translation settings for content translation test.
   */
  protected function setTranslationSettings() {
    // Set up extra language.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // Enable translation for the current entity type and ensure the change is
    // picked up.
    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);
    drupal_static_reset();
    $this->entityTypeManager->clearCachedDefinitions();
    \Drupal::service('router.builder')->rebuild();
    $this->rebuildContainer();
  }

}
