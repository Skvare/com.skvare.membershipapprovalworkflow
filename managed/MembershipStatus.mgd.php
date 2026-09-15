<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Managed MembershipStatus entities for the approval workflow.
 *
 * All statuses are 'is_admin' => 1 (like core's Cancelled/Deceased) and carry
 * no start/end event, so CiviCRM's date-based status calculator never assigns
 * them automatically and never matches a date-window for them - they are only
 * ever set explicitly by this extension (approval dropdown / payment hook).
 *
 * The five statuses below this comment are declared as APIv4 entities with
 * 'match' => ['name'], instead of the flat APIv3 style used by the three
 * above. On a site where one of these was already created by hand (e.g. via
 * Administer > CiviMember > Membership Status Rules) ahead of this file
 * declaring it, matching on 'name' makes reconciliation update that existing
 * row in place instead of erroring on a duplicate `name` or creating a
 * second, redundant status - see Civi\Api4\Generic\Traits\MatchParamTrait.
 * "Not Fulfilled" is the one exception: CRM_Membershipapprovalworkflow_
 * Upgrader::upgrade_1003() (Utils::renameNotFulfilledStatusTypo()) fixes up
 * a "Not Fullfilled" typo some sites already have under that name, so the
 * `name` match above actually finds it.
 */
return [
  [
    'name' => 'MembershipApprovalWorkflow_Status_PendingApprovalPaymentReceived',
    'entity' => 'MembershipStatus',
    'params' => [
      'version' => 3,
      'name' => 'Pending Approval/Payment Received',
      'label' => E::ts('Pending Approval/Payment Received'),
      'start_event' => NULL,
      'end_event' => NULL,
      'is_current_member' => 0,
      'is_admin' => 1,
      'is_active' => 1,
      'is_default' => 0,
      'is_reserved' => 0,
      'weight' => 7,
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_UnderReview',
    'entity' => 'MembershipStatus',
    'params' => [
      'version' => 3,
      'name' => 'Under Review',
      'label' => E::ts('Under Review'),
      'start_event' => NULL,
      'end_event' => NULL,
      'is_current_member' => 0,
      'is_admin' => 1,
      'is_active' => 1,
      'is_default' => 0,
      'is_reserved' => 0,
      'weight' => 8,
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_ApprovedPendingPayment',
    'entity' => 'MembershipStatus',
    'params' => [
      'version' => 3,
      'name' => 'Approved/Pending Payment',
      'label' => E::ts('Approved/Pending Payment'),
      'start_event' => NULL,
      'end_event' => NULL,
      'is_current_member' => 0,
      'is_admin' => 1,
      'is_active' => 1,
      'is_default' => 0,
      'is_reserved' => 0,
      'weight' => 9,
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_Denied',
    'entity' => 'MembershipStatus',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Denied',
        'label' => E::ts('Denied'),
        'start_event' => NULL,
        'end_event' => NULL,
        'is_current_member' => FALSE,
        'is_admin' => TRUE,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'weight' => 10,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_NotFulfilled',
    'entity' => 'MembershipStatus',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Not Fulfilled',
        'label' => E::ts('Not Fulfilled'),
        'start_event' => NULL,
        'end_event' => NULL,
        'is_current_member' => FALSE,
        'is_admin' => TRUE,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'weight' => 11,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_Suspended',
    'entity' => 'MembershipStatus',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Suspended',
        'label' => E::ts('Suspended'),
        'start_event' => NULL,
        'end_event' => NULL,
        'is_current_member' => FALSE,
        'is_admin' => TRUE,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'weight' => 12,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_Removed',
    'entity' => 'MembershipStatus',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Removed',
        'label' => E::ts('Removed'),
        'start_event' => NULL,
        'end_event' => NULL,
        'is_current_member' => FALSE,
        'is_admin' => TRUE,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'weight' => 13,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_Status_CancelledByMember',
    'entity' => 'MembershipStatus',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Cancelled by Member',
        'label' => E::ts('Cancelled by Member'),
        'start_event' => NULL,
        'end_event' => NULL,
        'is_current_member' => FALSE,
        'is_admin' => TRUE,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
        'weight' => 14,
      ],
      'match' => ['name'],
    ],
  ],
];
