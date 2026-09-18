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

  /**
   * addButtons() subName for the second submit button - see
   * buildQuickForm()/postProcess().
   */
  const BUTTON_SUBNAME_SEND_NOTIFICATION = 'send_notification';

  private $membershipId;
  private $contactId;
  private $currentStatusName;
  private $allowedActions;

  public function preProcess() {
    $this->membershipId = CRM_Utils_Request::retrieve('id', 'Positive', $this, TRUE);
    $membership = CRM_Membershipapprovalworkflow_Utils::getMembership($this->membershipId);
    CRM_Membershipapprovalworkflow_Utils::assertPrimaryMembership($membership);
    CRM_Membershipapprovalworkflow_Utils::assertMembershipTypeInWorkflow($membership);
    // Do not trust a contact ID from the URL. It must always match the
    // membership being acted on.
    $this->contactId = $membership['contact_id'];
    $this->currentStatusName = CRM_Membershipapprovalworkflow_Utils::getStatusNameById($membership['status_id']);
    $paymentReceived = CRM_Membershipapprovalworkflow_Utils::hasReceivedPayment($this->membershipId);
    $this->allowedActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions($this->currentStatusName, $paymentReceived);
    $this->assign('currentStatusLabel', CRM_Core_PseudoConstant::getLabel('CRM_Member_BAO_Membership', 'status_id', $membership['status_id']));
    $this->assign('contactId', $this->contactId);
    $this->assign('hasActions', !empty($this->allowedActions));
    $this->assign('statusSequence', CRM_Membershipapprovalworkflow_Utils::statusSequence($paymentReceived));
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
        [
          'type' => 'submit',
          'name' => E::ts('Apply and Send Notification'),
          'subName' => self::BUTTON_SUBNAME_SEND_NOTIFICATION,
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
      $sendNotification = $this->controller->getButtonName()
        === $this->getButtonName('submit', self::BUTTON_SUBNAME_SEND_NOTIFICATION);
      CRM_Membershipapprovalworkflow_Utils::applyAction($this->membershipId, $values['approval_action'], $sendNotification);
      CRM_Core_Session::setStatus(E::ts('Membership status updated.'), E::ts('Saved'), 'success');
    }
  }

}
