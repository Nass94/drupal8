<?php

namespace Drupal\content_access\Tests;

use Drupal\Core\Session\AccountInterface;
use Drupal\simpletest\WebTestBase;

/**
 * Automated SimpleTest Case for using content access module with acl module.
 *
 * @group Access
 */
class ContentAccessAclTestCase extends WebTestBase {
  use ContentAccessTestHelperTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['content_access', 'acl'];

  protected $test_user;
  protected $admin_user;
  protected $content_type;
  protected $node1;

  /**
   * Setup configuration before each test.
   */
  public function setUp() {
    parent::setUp();

    if (!\Drupal::moduleHandler()->moduleExists('acl')) {
      $this->pass('No ACL module present, skipping test');
      return;
    }

    // Create test user with separate role.
    $this->test_user = $this->drupalCreateUser();

    // Get the value of the new role.
    // @see drupalCreateUser().
    $test_user_roles = $this->test_user->getRoles();
    foreach ($test_user_roles as $role) {
      if (!in_array($role, [AccountInterface::AUTHENTICATED_ROLE])) {
        $this->rid = $role;
        break;
      }
    }

    // Create admin user.
    $this->admin_user = $this->drupalCreateUser([
      'access content',
      'administer content types',
      'grant content access',
      'grant own content access',
      'bypass node access',
      'access administration pages'
    ]);
    $this->drupalLogin($this->admin_user);

    // Rebuild content access permissions.
    node_access_rebuild();

    // Create test content type.
    $this->content_type = $this->drupalCreateContentType();

    // Create test node.
    $this->node1 = $this->drupalCreateNode(['type' => $this->content_type->id()]);
  }

  /**
   * Test Viewing accessibility with permissions for single users.
   */
  public function testViewAccess() {
    // Exit test if ACL module could not be enabled.
    if (!\Drupal::moduleHandler()->moduleExists('acl')) {
      $this->pass('No ACL module present, skipping test');
      return;
    }

    // Restrict access to this content type.
    // Enable per node access control.
    $access_permissions = [
      'view[anonymous]' => FALSE,
      'view[authenticated]' => FALSE,
      'per_node' => TRUE,
    ];
    $this->changeAccessContentType($access_permissions);

    // Allow access for test user.
    $edit = [
      'acl[view][add]' => $this->test_user->getUsername(),
    ];
    $this->drupalPostForm('node/'. $this->node1->id() . '/access', $edit, t('Add User'));
    $this->drupalPostForm(NULL, [], t('Submit'));

    // Logout admin, try to access the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id());
    $this->assertText(t('Access denied'), 'node is not viewable');

    // Login test user, view access should be allowed now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id());
    $this->assertNoText(t('Access denied'), 'node is viewable');

    // Login admin and disable per node access.
    $this->drupalLogin($this->admin_user);
    $this->changeAccessPerNode(FALSE);

    // Logout admin, try to access the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id());
    $this->assertText(t('Access denied'), 'node is not viewable');

    // Login test user, view access should be denied now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id());
    $this->assertText(t('Access denied'), 'node is not viewable');
  }

  /**
   * Test Editing accessibility with permissions for single users.
   */
  public function testEditAccess() {
    // Exit test if ACL module could not be enabled.
    if (!\Drupal::moduleHandler()->moduleExists('acl')) {
      $this->pass('No ACL module present, skipping test');
      return;
    }

    // Enable per node access control.
    $this->changeAccessPerNode();

    // Allow edit access for test user.
    $edit = [
      'acl[update][add]' => $this->test_user->getUsername(),
    ];
    $this->drupalPostForm('node/' . $this->node1->id() . '/access', $edit, t('Add User'));
    $this->drupalPostForm(NULL, [], t('Submit'));

    // Logout admin, try to edit the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id() . '/edit');
    $this->assertText(t('Access denied'), 'node is not editable');

    // Login test user, edit access should be allowed now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id() . '/edit');
    $this->assertNoText(t('Access denied'), 'node is editable');

    // Login admin and disable per node access.
    $this->drupalLogin($this->admin_user);
    $this->changeAccessPerNode(FALSE);

    // Logout admin, try to edit the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id() . '/edit');
    $this->assertText(t('Access denied'), 'node is not editable');

    // Login test user, edit access should be denied now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id() . '/edit');
    $this->assertText(t('Access denied'), 'node is not editable');
  }

  /**
   * Test Deleting accessibility with permissions for single users.
   */
  public function testDeleteAccess() {
    // Exit test if ACL module could not be enabled.
    if (!\Drupal::moduleHandler()->moduleExists('acl')) {
      $this->pass('No ACL module present, skipping test');
      return;
    }

    // Enable per node access control.
    $this->changeAccessPerNode();

    // Allow delete access for test user.
    $edit = [
      'acl[delete][add]' => $this->test_user->getUsername(),
    ];
    $this->drupalPostForm('node/' . $this->node1->id() . '/access', $edit, t('Add User'));
    $this->drupalPostForm(NULL, [], t('Submit'));

    // Logout admin, try to delete the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id() . '/delete');
    $this->assertText(t('Access denied'), 'node is not deletable');

    // Login test user, delete access should be allowed now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id() . '/delete');
    $this->assertNoText(t('Access denied'), 'node is deletable');

    // Login admin and disable per node access.
    $this->drupalLogin($this->admin_user);
    $this->changeAccessPerNode(FALSE);

    // Logout admin, try to delete the node anonymously.
    $this->drupalLogout();
    $this->drupalGet('node/' . $this->node1->id() . '/delete');
    $this->assertText(t('Access denied'), 'node is not deletable');

    // Login test user, delete access should be denied now.
    $this->drupalLogin($this->test_user);
    $this->drupalGet('node/' . $this->node1->id() . '/delete');
    $this->assertText(t('Access denied'), 'node is not deletable');
  }

}
