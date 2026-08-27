<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Managed MembershipStatus entities for the approval workflow.
 *
 * Both statuses are 'is_admin' => 1 (like core's Cancelled/Deceased) and carry
 * no start/end event, so CiviCRM's date-based status calculator never assigns
 * them automatically and never matches a date-window for them - they are only
 * ever set explicitly by this extension (approval dropdown / payment hook).
 */
return [
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
];
