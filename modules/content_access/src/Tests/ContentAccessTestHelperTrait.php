<?php

namespace Drupal\content_access\Tests;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;

/**
 * Helper class with auxiliary functions for content access module tests.
 */
trait ContentAccessTestHelperTrait {

  protected $rid = AccountInterface::AUTHENTICATED_ROLE;

  /**
   * Change access permissions for a content type.
   */
  public function changeAccessContentType($access_settings) {
    $this->drupalPostForm(
      'admin/structure/types/manage/' . $this->content_type->id() . '/access',
      $access_settings,
      t('Submit')
    );
    // Both these may be printed:
    // 'Permissions have been changed' || 'No change' => 'change'
    $this->assertText(
      t('change'),
      'submitted access rules of content type'
    );
  }

  /**
   * Change access permissions for a content type by a given keyword for the role of the user.
   *
   */
  public function changeAccessContentTypeKeyword($keyword, $access = TRUE, AccountInterface $user = NULL) {
    debug($keyword, 'KW');
    $roles = [];

    if ($user === NULL) {
      $role = Role::load($this->rid);
      $roles[$role->id()] = $role->id();
    }
    else {
      $user_roles = $user->getRoles();
      foreach ($user_roles as $role) {
        $roles[$role] = $role;
        break;
      }
    }

    $access_settings = [
      $keyword . '[' . key($roles) . ']' => $access,
    ];

    $this->changeAccessContentType($access_settings);
  }

  /**
   * Change the per node access setting for a content type.
   */
  public function changeAccessPerNode($access = TRUE) {
    $access_permissions = [
      'per_node' => $access,
    ];
    $this->changeAccessContentType($access_permissions);
  }

  /**
   * Change access permissions for a node by a given keyword (view, update or delete).
   *
   */
  public function changeAccessNodeKeyword(NodeInterface $node, $keyword, $access = TRUE) {
    $user = $this->test_user;
    $user_roles = $user->getRoles();
    foreach ($user_roles as $rid) {
      $role = Role::load($rid);
      $roles[$role->id()] = $role->get('label');
    }

    $access_settings = [
      $keyword . '[' . key($roles) . ']' => $access,
    ];

    $this->changeAccessNode($node, $access_settings);
  }

  /**
   * Change access permission for a node.
   */
  public function changeAccessNode(NodeInterface $node, $access_settings) {
    $this->drupalPostForm('node/' . $node->id() . '/access', $access_settings, t('Submit'));
    $this->assertText(
      t('Your changes have been saved.'),
      'access rules of node were updated successfully'
    );
  }

}
