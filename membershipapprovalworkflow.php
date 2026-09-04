<?php

require_once 'membershipapprovalworkflow.civix.php';

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function membershipapprovalworkflow_civicrm_config(&$config): void {
  _membershipapprovalworkflow_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * Requirement 4: the 'New' membership status must never be used. Rather
 * than trying to intercept every place core could assign it, deactivating
 * it removes it from CiviCRM's date-based status calculator entirely (the
 * calculator only ever considers is_active=1 statuses), from the status
 * picker, and from the renewal path - memberships that would previously
 * have landed on 'New' now land on 'Current' instead, since weight-wise
 * that's the very next matching status.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function membershipapprovalworkflow_civicrm_install(): void {
  _membershipapprovalworkflow_civix_civicrm_install();
  CRM_Membershipapprovalworkflow_Utils::deactivateNewStatus();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * Restore the 'New' status so uninstalling this extension leaves the site
 * in its original configuration.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function membershipapprovalworkflow_civicrm_uninstall(): void {
  CRM_Membershipapprovalworkflow_Utils::restoreNewStatus();
}

/**
 * Implements hook_civicrm_disable().
 *
 * Restore the site's original New-status state whenever this extension stops
 * controlling the membership workflow.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function membershipapprovalworkflow_civicrm_disable(): void {
  CRM_Membershipapprovalworkflow_Utils::restoreNewStatus();
}

/**
 * Implements hook_civicrm_links().
 *
 * Adds a "Membership Approval" action to the row-action menu on the
 * contact's Membership tab, deliberately separate from the core Membership
 * edit form (requirement 3). Only offered on the primary membership - an
 * inherited membership (owner_membership_id set) tracks its owner's
 * status automatically via core's own createRelatedMemberships(), so
 * approving it directly would be meaningless.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_links/
 */
function membershipapprovalworkflow_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  $allowRegions = ['membership.selector.row', 'membership.tab.row'];
  if (in_array($op, $allowRegions) && $objectName === 'Membership') {
    $ownerMembershipId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $objectId, 'owner_membership_id');
    if ($ownerMembershipId) {
      return;
    }

    $statusId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $objectId, 'status_id');
    $statusName = CRM_Membershipapprovalworkflow_Utils::getStatusNameById($statusId);
    if (!CRM_Membershipapprovalworkflow_Utils::getAllowedActions($statusName)) {
      return;
    }

    $links[] = [
      'name' => E::ts('Membership Approval'),
      'url' => 'civicrm/membership/approve',
      'qs' => 'reset=1&id=%%id%%',
      'title' => E::ts('Membership Approval'),
      'ref' => 'membership-approval',
      'weight' => 60,
      'bit' => CRM_Core_Action::UPDATE,
    ];
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre/
 */
function membershipapprovalworkflow_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName === 'Membership' && $op === 'create') {
    CRM_Membershipapprovalworkflow_Utils::forcePendingOnCreate($params);
  }
  elseif ($objectName === 'Membership' && $op === 'edit') {
    CRM_Membershipapprovalworkflow_Utils::preserveWorkflowStatusOnEdit($id, $params);
  }
  elseif ($objectName === 'Contribution' && $id && !empty($params['prevContribution'])) {
    Civi::$statics['membershipapprovalworkflow']['prevContributionStatus'][$id] = $params['prevContribution']->contribution_status_id;
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * Membership status is controlled by the dedicated approval action, not by
 * the standard membership add/edit form.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildForm/
 */
function membershipapprovalworkflow_civicrm_buildForm($formName, &$form): void {
  if ($formName !== 'CRM_Member_Form_Membership') {
    return;
  }

  $fields = array_filter([
    $form->elementExists('status_id') ? 'status_id' : NULL,
    $form->elementExists('is_override') ? 'is_override' : NULL,
    $form->elementExists('status_override_end_date') ? 'status_override_end_date' : NULL,
  ]);
  if ($fields) {
    $form->freeze($fields);
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * Requirements 2D / 5: when a contribution linked to a membership becomes
 * Completed: if that membership is "Approved/Pending Payment", move it to
 * Current with start date = payment date; if it is still the initial
 * "Pending" (payment made at signup instead of pay-later), move it to
 * "Pending Approval/Payment Received" instead. See
 * CRM_Membershipapprovalworkflow_Utils::handleContributionCompleted().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post/
 */
function membershipapprovalworkflow_civicrm_post($op, $objectName, $objectId, $objectRef) {
  if ($objectName !== 'Contribution' || !in_array($op, ['create', 'edit'], TRUE)) {
    return;
  }

  $newStatusId = is_object($objectRef) ? $objectRef->contribution_status_id : ($objectRef['contribution_status_id'] ?? NULL);
  $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
  if (!$newStatusId || (int) $newStatusId !== (int) $completedStatusId) {
    return;
  }

  $oldStatusId = Civi::$statics['membershipapprovalworkflow']['prevContributionStatus'][$objectId] ?? NULL;
  if ($oldStatusId && (int) $oldStatusId === (int) $completedStatusId) {
    // Was already Completed before this save - not a fresh transition.
    return;
  }

  CRM_Membershipapprovalworkflow_Utils::handleContributionCompleted($objectId);
}

/**
 * Implements hook_civicrm_alterCalculatedMembershipStatus().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterCalculatedMembershipStatus/
 */
function membershipapprovalworkflow_civicrm_alterCalculatedMembershipStatus(&$membershipStatus, $arguments, $membership) {
  CRM_Membershipapprovalworkflow_Utils::preserveProtectedStatus($membershipStatus, $membership);
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function membershipapprovalworkflow_civicrm_enable(): void {
  _membershipapprovalworkflow_civix_civicrm_enable();
  CRM_Membershipapprovalworkflow_Utils::deactivateNewStatus();
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds the "Membership Approval Workflow Notifications" settings screen
 * under Administer > CiviMember, so an administrator can toggle each
 * workflow email on or off - see CRM_Membershipapprovalworkflow_Form_Settings.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function membershipapprovalworkflow_civicrm_navigationMenu(&$menu) {
  _membershipapprovalworkflow_civix_insert_navigation_menu($menu, 'Administer/CiviMember', [
    'label' => E::ts('Membership Approval Workflow Notifications'),
    'name' => 'membershipapprovalworkflow_settings',
    'url' => 'civicrm/admin/membershipapprovalworkflow',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _membershipapprovalworkflow_civix_navigationMenu($menu);
}
