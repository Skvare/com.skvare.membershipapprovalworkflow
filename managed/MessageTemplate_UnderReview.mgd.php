<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Managed message template sent by
 * CRM_Membershipapprovalworkflow_Utils::sendUnderReviewNotification() when
 * a membership moves from Pending (or Pending Approval/Payment Received)
 * into "Under Review" via the approval dropdown. Gated by the
 * `membershipapprovalworkflow_notify_under_review` setting - see
 * CRM_Membershipapprovalworkflow_Form_Settings.
 *
 * Follows the same reserved/editable pair pattern as
 * MessageTemplate_UnderReviewApproved.mgd.php so admins can customize the
 * editable copy from Administer > Communications > Message Templates while
 * still being able to revert to the original.
 */
$htmlText = file_get_contents(__DIR__ . '/under_review_html.tpl');
$plainText = file_get_contents(__DIR__ . '/under_review_text.tpl');
$subject = file_get_contents(__DIR__ . '/under_review_subject.tpl');

return [
  [
    'name' => 'MembershipApprovalWorkflow_UnderReview_Reserved',
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
        'workflow_name' => 'membershipapprovalworkflow_under_review',
        'msg_title' => E::ts('Membership Approval Workflow - Under Review'),
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
    'name' => 'MembershipApprovalWorkflow_UnderReview_Editable',
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
        'workflow_name' => 'membershipapprovalworkflow_under_review',
        'msg_title' => E::ts('Membership Approval Workflow - Under Review'),
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
