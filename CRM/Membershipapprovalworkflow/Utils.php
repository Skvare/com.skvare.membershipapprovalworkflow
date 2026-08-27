<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Central logic for the membership approval workflow.
 *
 * Every status/date change made here goes through the standard
 * Membership.create API so that CiviCRM core's own related-membership
 * propagation (CRM_Member_BAO_Membership::createRelatedMemberships(),
 * called unconditionally at the end of BAO::create()) keeps the
 * organization membership and its inherited individual memberships in
 * sync. Nothing here talks to the database directly.
 */
class CRM_Membershipapprovalworkflow_Utils {

  const STATUS_UNDER_REVIEW = 'Under Review';
  const STATUS_APPROVED_PENDING_PAYMENT = 'Approved/Pending Payment';
  const STATUS_PENDING = 'Pending';
  const STATUS_CURRENT = 'Current';
  const STATUS_NEW = 'New';

  /**
   * Action keys used by the approval dropdown, in display order.
   */
  const ACTION_UNDER_REVIEW = 'under_review';
  const ACTION_APPROVED_PENDING_PAYMENT = 'approved_pending_payment';
  const ACTION_APPROVED = 'approved';

  private static $statusIdCache = [];

  /**
   * @return int|NULL
   */
  public static function getStatusIdByName($name) {
    if (!array_key_exists($name, self::$statusIdCache)) {
      self::$statusIdCache[$name] = CRM_Core_DAO::getFieldValue(
        'CRM_Member_DAO_MembershipStatus',
        $name,
        'id',
        'name'
      );
    }
    return self::$statusIdCache[$name];
  }

  /**
   * @return string|NULL
   */
  public static function getStatusNameById($statusId) {
    if (!$statusId) {
      return NULL;
    }
    return CRM_Core_DAO::getFieldValue(
      'CRM_Member_DAO_MembershipStatus',
      $statusId,
      'name',
      'id'
    );
  }

  /**
   * Status names this extension owns and no other process should silently
   * move a membership out of.
   */
  public static function protectedStatusNames() {
    return [self::STATUS_PENDING, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED_PENDING_PAYMENT];
  }

  /**
   * Which approval actions are valid from the membership's current status.
   *
   * @param string $currentStatusName
   * @return array
   *   Action key => label.
   */
  public static function getAllowedActions($currentStatusName) {
    $actions = [
      self::ACTION_UNDER_REVIEW => E::ts('Under Review'),
      self::ACTION_APPROVED_PENDING_PAYMENT => E::ts('Approved/Pending Payment'),
      self::ACTION_APPROVED => E::ts('Approved'),
    ];

    switch ($currentStatusName) {
      case self::STATUS_PENDING:
        return $actions;

      case self::STATUS_UNDER_REVIEW:
        unset($actions[self::ACTION_UNDER_REVIEW]);
        return $actions;

      case self::STATUS_APPROVED_PENDING_PAYMENT:
        return [self::ACTION_APPROVED => $actions[self::ACTION_APPROVED]];

      default:
        // Current, Grace, Expired, Cancelled, Deceased, etc. - workflow is done.
        return [];
    }
  }

  /**
   * Apply one approval-dropdown action to a membership (and, via core,
   * its related/inherited memberships).
   *
   * @param int $membershipId
   * @param string $action
   *   One of the ACTION_* constants.
   *
   * @return array
   *   The updated membership (api3 Membership.create result values).
   * @throws \CRM_Core_Exception
   */
  public static function applyAction($membershipId, $action) {
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membershipId]);

    $params = [
      'id' => $membershipId,
      'skipStatusCal' => TRUE,
      'is_override' => 0,
      'status_override_end_date' => '',
    ];

    switch ($action) {
      case self::ACTION_UNDER_REVIEW:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_UNDER_REVIEW);
        break;

      case self::ACTION_APPROVED_PENDING_PAYMENT:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_APPROVED_PENDING_PAYMENT);
        break;

      case self::ACTION_APPROVED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_CURRENT);
        $params += self::datesForStart($membership, date('Y-m-d'));
        break;

      default:
        throw new CRM_Core_Exception(E::ts('Unknown membership approval action: %1', [1 => $action]));
    }

    return civicrm_api3('Membership', 'create', $params)['values'][$membershipId] ?? [];
  }

  /**
   * Move an "Approved/Pending Payment" membership to Current once its
   * linked payment is received, per requirement 2D / 5.
   *
   * @param int $membershipId
   * @param string $paymentDate
   *   Y-m-d (or any CRM_Utils_Date-parseable) date the payment was received.
   */
  public static function markCurrentOnPayment($membershipId, $paymentDate) {
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membershipId]);

    $params = [
      'id' => $membershipId,
      'skipStatusCal' => TRUE,
      'is_override' => 0,
      'status_override_end_date' => '',
      'status_id' => self::getStatusIdByName(self::STATUS_CURRENT),
    ];
    $params += self::datesForStart($membership, $paymentDate);

    civicrm_api3('Membership', 'create', $params);
  }

  /**
   * hook_civicrm_pre callback (Membership, op=create) - force every brand
   * new, non-inherited membership to start as Pending, per requirement 1,
   * regardless of whether it was submitted with immediate payment or
   * pay-later. Staff must then move it forward via the approval dropdown.
   */
  public static function forcePendingOnCreate(array &$params) {
    // Inherited memberships are managed by core's own
    // createRelatedMemberships(), which syncs status from the owner
    // membership and already sets skipStatusCal - leave them alone.
    if (!empty($params['owner_membership_id'])) {
      return;
    }
    // Something already made a deliberate, explicit status decision
    // (e.g. core's own pay-later handling, an import, or this extension) -
    // don't second-guess it.
    if (!empty($params['skipStatusCal'])) {
      return;
    }
    $pendingId = self::getStatusIdByName(self::STATUS_PENDING);
    if (!$pendingId) {
      return;
    }
    $params['status_id'] = $pendingId;
    $params['skipStatusCal'] = TRUE;
    // A Pending membership isn't active yet - it has no effective period.
    $params['start_date'] = '';
    $params['end_date'] = '';
  }

  /**
   * hook_civicrm_post callback (Contribution) - when a contribution linked
   * to a membership currently "Approved/Pending Payment" is completed,
   * move that membership to Current with start date = payment date
   * (requirements 2D / 5).
   */
  public static function handleContributionCompleted($contributionId) {
    $paymentDate = civicrm_api3('Contribution', 'getvalue', [
      'id' => $contributionId,
      'return' => 'receive_date',
    ]);

    $membershipPayments = civicrm_api3('MembershipPayment', 'get', [
      'contribution_id' => $contributionId,
      'return' => ['membership_id'],
    ])['values'];

    foreach ($membershipPayments as $membershipPayment) {
      $membershipId = $membershipPayment['membership_id'];
      $statusId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $membershipId, 'status_id');
      if (self::getStatusNameById($statusId) === self::STATUS_APPROVED_PENDING_PAYMENT) {
        self::markCurrentOnPayment($membershipId, $paymentDate);
      }
    }
  }

  /**
   * hook_civicrm_alterCalculatedMembershipStatus callback - keep "Under
   * Review" / "Approved/Pending Payment" memberships in place. They are
   * only ever meant to change via the approval dropdown or the payment
   * hook above, never via CiviCRM's date-based status calculator (the
   * nightly job, membership create/edit, or the renewal/order-complete
   * flow all funnel through here).
   */
  public static function preserveProtectedStatus(array &$membershipStatus, array $membership) {
    $membershipId = $membership['id'] ?? NULL;
    if (!$membershipId) {
      return;
    }
    $currentStatusId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $membershipId, 'status_id');
    $currentStatusName = self::getStatusNameById($currentStatusId);
    if (in_array($currentStatusName, [self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED_PENDING_PAYMENT], TRUE)) {
      $membershipStatus = ['id' => $currentStatusId, 'name' => $currentStatusName];
    }
  }

  /**
   * Compute join/start/end dates for moving a membership to Current,
   * preserving its original join date and using core's own duration
   * calculation so Grace/Expired keep working afterwards (requirement 7).
   */
  private static function datesForStart(array $membership, $startDate) {
    $dates = CRM_Member_BAO_MembershipType::getDatesForMembershipType(
      $membership['membership_type_id'],
      $membership['join_date'] ?? NULL,
      $startDate
    );
    return [
      'join_date' => $dates['join_date'],
      'start_date' => $dates['start_date'],
      'end_date' => $dates['end_date'],
    ];
  }

}
