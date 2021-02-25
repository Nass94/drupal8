<?php
// @codingStandardsIgnoreFile
/**
 * @file
 * A performance test for encrypted fields.
 */

if (PHP_SAPI !== 'cli') {
  return;
}

/**
 * This script makes the following assumptions
 * - It is working on a fresh install of the Standard profile that ships with
 *   core.
 * - The dependencies of field_encrypt are installed
 * - The https://www.drupal.org/project/sodium module is installed.
 *
 * The script is designed to be run via drush. i.e:
 * - vendor/bin/drush scr modules/field_encrypt/tests/scripts/performance_test.php
 *
 * You can set the environment variable FIELD_ENCRYPT_QUANTITY to change how
 * many nodes are created. The default is 1000.
 */

// Create test fields.
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;

// Create a 256bit testkey.
if (!Key::load('testing_key_256')) {
  $key_256 = Key::create([
    'id' => 'testing_key_256',
    'label' => 'Testing Key 256 bit',
    'key_type' => "encryption",
    'key_type_settings' => ['key_size' => '256'],
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => 'F)J@NcRfUjXn2r5u8x/A?D(G-KaPdSgV'],
  ]);
  $key_256->save();
}

// Create test encryption profiles.
if (!EncryptionProfile::load('encryption_profile_1')) {
  $encryption_profile_1 = EncryptionProfile::create([
    'id' => 'encryption_profile_1',
    'label' => 'Encryption profile 1',
    'encryption_method' => 'sodium',
    'encryption_key' => $key_256->id(),
  ]);
  $encryption_profile_1->save();
}

$field_created = FALSE;
/** @var FieldStorageConfig $single_field_storage */
if (!FieldStorageConfig::loadByName('node', 'field_test_single')) {
  $field_created = TRUE;
  $single_field_storage = FieldStorageConfig::create([
    'field_name' => 'field_test_single',
    'entity_type' => 'node',
    'type' => 'text_with_summary',
    'cardinality' => 1,
  ]);

  $single_field_storage->setThirdPartySetting('field_encrypt', 'encrypt', TRUE);
  $single_field_storage->setThirdPartySetting('field_encrypt', 'properties', [
    'value' => 'value',
    'summary' => 'summary'
  ]);
  // These setting is only used by 8.x-2.x and not 3.0.x.
  $single_field_storage->setThirdPartySetting('field_encrypt', 'encryption_profile', 'encryption_profile_1');
  $single_field_storage->setThirdPartySetting('field_encrypt', 'uncacheable', TRUE);

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

  /** @var FieldStorageConfig $multi_field_storage */
  $multi_field_storage = FieldStorageConfig::create([
    'field_name' => 'field_test_multi',
    'entity_type' => 'node',
    'type' => 'string',
    'cardinality' => 3,
  ]);
  $multi_field_storage->setThirdPartySetting('field_encrypt', 'encrypt', TRUE);
  $multi_field_storage->setThirdPartySetting('field_encrypt', 'properties', ['value' => 'value']);
  // These setting is only used by 8.x-2.x and not 3.0.x.
  $multi_field_storage->setThirdPartySetting('field_encrypt', 'encryption_profile', 'encryption_profile_1');
  $multi_field_storage->setThirdPartySetting('field_encrypt', 'uncacheable', TRUE);

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
}
// This setting is only used by 3.0.x and not 8.2.x
\Drupal::configFactory()->getEditable('field_encrypt.settings')
  ->set('encryption_profile', 'encryption_profile_1')
  ->save();

if ($field_created) {
  // This should not be necessary but it provides a clean starting point. If the
  // scripts has been run on the install already it does not call
  // drupal_flush_all_caches() to make it easier to get a profile that only
  // concerns encrypting and decrypting.
  drupal_flush_all_caches();
}

$default_filter_format = filter_default_format();
$values = [
  'field_test_single' => [
    [
      'value' => "Lorem ipsum dolor sit amet.",
      'summary' => "Lorem ipsum",
      'format' => $default_filter_format,
    ],
  ],
  'field_test_multi' => [
    ['value' => "one lorem"],
    ['value' => "two lorem"],
    ['value' => "three lorem"],
  ],
  'type' => 'page',
  'uid' => 0,
];
$node_ids = [];
$quantity = (int) (getenv('FIELD_ENCRYPT_QUANTITY') ?? 1000);
$start = microtime(TRUE);
for ($i = 0; $i < $quantity; $i++) {
  $values['title'] = 'Test node ' . ($i + 1);
  $node = Node::create($values);
  $node->save();
  $node_ids[] = $node->id();
}
print 'Created ' . count($node_ids) . ' encrypted nodes in : ' . round((microtime(TRUE) - $start),2) . " seconds\n";

if ($field_created) {
  drupal_flush_all_caches();
}
// Ensure static caches play no part.
\Drupal::entityTypeManager()->getStorage('node')->resetCache();

$start = microtime(TRUE);
foreach ($node_ids as $nid) {
  $node = Node::load($nid);
}
print 'Decrypted ' . count($node_ids) . ' encrypted nodes in : ' . round((microtime(TRUE) - $start),2) . " seconds\n";
