<?php

use Civi\Api4\MessageTemplate;

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
    $templates = MessageTemplate::get(FALSE)
      ->addSelect('id', 'msg_html', 'msg_text')
      ->addWhere('workflow_name', '=', 'membershipapprovalworkflow_under_review_approved')
      ->addWhere('is_reserved', '=', FALSE)
      ->execute();

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
        MessageTemplate::update(FALSE)
          ->addWhere('id', '=', $template['id'])
          ->setValues([
            'msg_html' => $html,
            'msg_text' => $text,
          ])
          ->execute();
      }
    }
    return TRUE;
  }

}
