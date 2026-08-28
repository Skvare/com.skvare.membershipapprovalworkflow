<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Membership Approval action, linked from the "Membership Approval" link
 * on the contact's Membership tab (see hook_civicrm_links in
 * membershipapprovalworkflow.php). Deliberately separate from the core
 * Membership edit form - the only thing this screen does is move the
 * membership to its next workflow status.
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Membershipapprovalworkflow_Form_Approve extends CRM_Core_Form {

  private $membershipId;
  private $contactId;
  private $currentStatusName;
  private $allowedActions;

  public function preProcess() {
    $this->membershipId = CRM_Utils_Request::retrieve('id', 'Positive', $this, TRUE);
    $this->contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);

    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $this->membershipId]);
    $this->currentStatusName = CRM_Membershipapprovalworkflow_Utils::getStatusNameById($membership['status_id']);
    $this->allowedActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions($this->currentStatusName);

    $this->assign('currentStatusLabel', CRM_Core_PseudoConstant::getLabel('CRM_Member_BAO_Membership', 'status_id', $membership['status_id']));
    $this->assign('contactId', $this->contactId);
    $this->assign('hasActions', !empty($this->allowedActions));
    $this->assign('statusSequence', CRM_Membershipapprovalworkflow_Utils::statusSequence());
    $this->assign('currentStatusName', $this->currentStatusName);

    CRM_Utils_System::setTitle(E::ts('Membership Approval'));
  }

  public function buildQuickForm() {
    if (!empty($this->allowedActions)) {
      $this->add(
        'select',
        'approval_action',
        E::ts('Set membership status to'),
        $this->allowedActions,
        TRUE,
        ['class' => 'crm-select2 huge']
      );

      $this->addButtons([
        [
          'type' => 'submit',
          'name' => E::ts('Apply'),
          'isDefault' => TRUE,
        ],
      ]);
    }

    $this->assign('backUrl', CRM_Utils_System::url('civicrm/contact/view', [
      'reset' => 1,
      'cid' => $this->contactId,
      'selectedChild' => 'member',
    ]));

    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    if (!empty($values['approval_action'])) {
      CRM_Membershipapprovalworkflow_Utils::applyAction($this->membershipId, $values['approval_action']);
      CRM_Core_Session::setStatus(E::ts('Membership status updated.'), E::ts('Saved'), 'success');
    }
  }

}
