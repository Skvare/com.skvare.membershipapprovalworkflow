<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

return [
  'membershipapprovalworkflow_new_status_was_active' => [
    'name' => 'membershipapprovalworkflow_new_status_was_active',
    'group' => 'membershipapprovalworkflow',
    'type' => 'Boolean',
    'default' => NULL,
    'title' => E::ts('Original New membership-status state'),
    'description' => E::ts('Internal setting used to restore the New membership status when the Membership Approval Workflow extension is disabled or uninstalled.'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipapprovalworkflow_notify_under_review' => [
    'name' => 'membershipapprovalworkflow_notify_under_review',
    'group' => 'membershipapprovalworkflow',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'title' => E::ts('Notify applicant: application moved to Under Review'),
    'description' => E::ts('Send an email when a membership moves from Pending (or Pending Approval/Payment Received) into Under Review.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['membershipapprovalworkflow' => ['weight' => 10]],
  ],
  'membershipapprovalworkflow_notify_approved_pending_payment' => [
    'name' => 'membershipapprovalworkflow_notify_approved_pending_payment',
    'group' => 'membershipapprovalworkflow',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'title' => E::ts('Notify applicant: approved, pending payment'),
    'description' => E::ts('Send an email when staff move a membership from Under Review to Approved/Pending Payment.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['membershipapprovalworkflow' => ['weight' => 20]],
  ],
  'membershipapprovalworkflow_notify_approved' => [
    'name' => 'membershipapprovalworkflow_notify_approved',
    'group' => 'membershipapprovalworkflow',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'title' => E::ts('Notify applicant: approved and active (Current)'),
    'description' => E::ts('Send an email when staff move a membership from Under Review directly to Approved (Current).'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['membershipapprovalworkflow' => ['weight' => 30]],
  ],
];
