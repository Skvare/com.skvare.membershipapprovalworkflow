<?php

/**
 * Upgrade steps for Membership Approval Workflow.
 */
class CRM_Membershipapprovalworkflow_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Preserve the legacy extension's uninstall behavior for sites upgrading
   * from 1.0.0, which did not record the original New-status state.
   */
  public function upgrade_1001(): bool {
    CRM_Membershipapprovalworkflow_Utils::seedLegacyNewStatusState();
    return TRUE;
  }

  /**
   * Replace the legacy site-specific payment URL in existing editable
   * templates without replacing administrator-authored template content.
   */
  public function upgrade_1002(): bool {
    $legacyUrls = [
      'https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}',
      'https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&amp;{contact.checksum}',
    ];
    $templates = civicrm_api3('MessageTemplate', 'get', [
      'workflow_name' => 'membershipapprovalworkflow_under_review_approved',
      'is_reserved' => 0,
      'return' => ['id', 'msg_html', 'msg_text'],
      'options' => ['limit' => 0],
    ])['values'];

    foreach ($templates as $template) {
      $html = str_replace(
        $legacyUrls,
        '{crmURL p=\'civicrm/my-dashboard\' q="id={contact.contact_id}&{contact.checksum}" a=1 h=1}',
        $template['msg_html'] ?? ''
      );
      $text = str_replace(
        $legacyUrls,
        '{crmURL p=\'civicrm/my-dashboard\' q="id={contact.contact_id}&{contact.checksum}" a=1}',
        $template['msg_text'] ?? ''
      );
      if ($html !== ($template['msg_html'] ?? '') || $text !== ($template['msg_text'] ?? '')) {
        civicrm_api3('MessageTemplate', 'create', [
          'id' => $template['id'],
          'msg_html' => $html,
          'msg_text' => $text,
        ]);
      }
    }
    return TRUE;
  }

}
