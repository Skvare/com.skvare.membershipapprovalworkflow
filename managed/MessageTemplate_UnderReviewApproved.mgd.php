<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Managed message template sent by
 * CRM_Membershipapprovalworkflow_Utils::sendUnderReviewApprovedNotification()
 * when staff move a membership out of "Under Review" into either
 * "Approved" (Current) or "Approved/Pending Payment" via the approval
 * dropdown. Both outcomes share this one template - it branches on the
 * $newStatusName token to word the two cases differently.
 *
 * Follows core's own reserved/editable pair pattern (see e.g.
 * ext/standaloneusers/managed/MessageTemplate_PasswordReset.mgd.php) so
 * admins can customize the editable copy from Administer > Communications
 * > Message Templates while still being able to revert to the original.
 */
$htmlText = file_get_contents(__DIR__ . '/under_review_approved_html.tpl');
$plainText = file_get_contents(__DIR__ . '/under_review_approved_text.tpl');
$subject = file_get_contents(__DIR__ . '/under_review_approved_subject.tpl');

return [
  [
    'name' => 'MembershipApprovalWorkflow_UnderReviewApproved_Reserved',
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
        'workflow_name' => 'membershipapprovalworkflow_under_review_approved',
        'msg_title' => E::ts('Membership Approval Workflow - Under Review Approved'),
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
    'name' => 'MembershipApprovalWorkflow_UnderReviewApproved_Editable',
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
        'workflow_name' => 'membershipapprovalworkflow_under_review_approved',
        'msg_title' => E::ts('Membership Approval Workflow - Under Review Approved'),
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
