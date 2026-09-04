<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Administer > CiviMember > Membership Approval Workflow Notifications
 * (civicrm/admin/membershipapprovalworkflow).
 *
 * Lets an administrator turn each of the workflow's email notifications on
 * or off independently. This form adds no logic of its own beyond the
 * title - CRM_Admin_Form_Setting already builds/saves the fields entirely
 * from metadata: every setting in
 * settings/MembershipApprovalWorkflow.setting.php tagged with
 * `'settings_pages' => ['membershipapprovalworkflow' => [...]]` (matching
 * this form's URL, per CRM_Admin_Form_SettingTrait::getSettingPageFilter())
 * is picked up automatically.
 */
class CRM_Membershipapprovalworkflow_Form_Settings extends CRM_Admin_Form_Setting {

  public function getTitle() {
    return E::ts('Membership Approval Workflow: Email Notifications');
  }

}
