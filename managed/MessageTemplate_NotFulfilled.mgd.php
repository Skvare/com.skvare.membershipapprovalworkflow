<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Managed message template sent by
 * CRM_Membershipapprovalworkflow_Utils::sendNotFulfilledNotification() when
 * staff move a membership from "Approved/Pending Payment" to
 * "Not Fulfilled" via the approval dropdown. Gated by the
 * `membershipapprovalworkflow_notify_not_fulfilled` setting - see
 * CRM_Membershipapprovalworkflow_Form_Settings.
 *
 * Follows the same reserved/editable pair pattern as
 * MessageTemplate_UnderReview.mgd.php so admins can customize the
 * editable copy from Administer > Communications > Message Templates
 * while still being able to revert to the original.
 */
$htmlText = file_get_contents(__DIR__ . '/not_fulfilled_html.tpl');
$plainText = file_get_contents(__DIR__ . '/not_fulfilled_text.tpl');
$subject = file_get_contents(__DIR__ . '/not_fulfilled_subject.tpl');

return [
  [
    'name' => 'MembershipApprovalWorkflow_NotFulfilled_Reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'match' => [
        'workflow_name',
        'is_reserved',
      ],
      'values' => [
        'workflow_name' => 'membershipapprovalworkflow_not_fulfilled',
        'msg_title' => E::ts('Membership Approval Workflow - Not Fulfilled'),
        'msg_subject' => $subject,
        'msg_text' => $plainText,
        'msg_html' => $htmlText,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
      ],
    ],
  ],
  [
    'name' => 'MembershipApprovalWorkflow_NotFulfilled_Editable',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'match' => [
        'workflow_name',
        'is_reserved',
      ],
      'values' => [
        'workflow_name' => 'membershipapprovalworkflow_not_fulfilled',
        'msg_title' => E::ts('Membership Approval Workflow - Not Fulfilled'),
        'msg_subject' => $subject,
        'msg_text' => $plainText,
        'msg_html' => $htmlText,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
      ],
    ],
  ],
];
