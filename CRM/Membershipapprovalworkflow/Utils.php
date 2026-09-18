<?php

use CRM_Membershipapprovalworkflow_ExtensionUtil as E;
use Civi\Api4\Contribution;
use Civi\Api4\Membership;
use Civi\Api4\MembershipStatus;

/**
 * Central logic for the membership approval workflow.
 *
 * Every status/date change made here goes through the standard
 * Membership.update API4 action so that CiviCRM core's own related-membership
 * propagation (CRM_Member_BAO_Membership::createRelatedMemberships(),
 * called unconditionally at the end of BAO::create()) keeps the
 * organization membership and its inherited individual memberships in
 * sync. MembershipPayment has no API4 entity in the supported CiviCRM core,
 * so its read-only link lookups use its DAO.
 */
class CRM_Membershipapprovalworkflow_Utils {

  const STATUS_UNDER_REVIEW = 'Under Review';
  const STATUS_APPROVED_PENDING_PAYMENT = 'Approved/Pending Payment';
  const STATUS_PENDING = 'Pending';
  const STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED = 'Pending Approval/Payment Received';
  const STATUS_CURRENT = 'Current';
  const STATUS_GRACE = 'Grace';
  const STATUS_DENIED = 'Denied';
  const STATUS_NOT_FULFILLED = 'Not Fulfilled';
  const STATUS_SUSPENDED = 'Suspended';
  const STATUS_REMOVED = 'Removed';
  const STATUS_EXPIRED = 'Expired';
  const STATUS_CANCELLED_BY_MEMBER = 'Cancelled by Member';

  const SETTING_NEW_STATUS_WAS_ACTIVE = 'membershipapprovalworkflow_new_status_was_active';

  /**
   * Settings (settings/MembershipApprovalWorkflow.setting.php) controlling
   * which approval-workflow notification emails actually get sent - see
   * CRM_Membershipapprovalworkflow_Form_Settings. All default to enabled,
   * matching this extension's behavior before these settings existed.
   */
  const SETTING_NOTIFY_UNDER_REVIEW = 'membershipapprovalworkflow_notify_under_review';
  const SETTING_NOTIFY_APPROVED_PENDING_PAYMENT = 'membershipapprovalworkflow_notify_approved_pending_payment';
  const SETTING_NOTIFY_APPROVED = 'membershipapprovalworkflow_notify_approved';
  const SETTING_NOTIFY_DENIED = 'membershipapprovalworkflow_notify_denied';
  const SETTING_NOTIFY_NOT_FULFILLED = 'membershipapprovalworkflow_notify_not_fulfilled';

  /**
   * Setting (settings/MembershipApprovalWorkflow.setting.php) listing which
   * membership types this workflow applies to. Empty/unset means "all
   * types" - see isMembershipTypeInWorkflow().
   */
  const SETTING_MEMBERSHIP_TYPES = 'membershipapprovalworkflow_membership_types';

  /**
   * Action keys used by the approval dropdown, in display order.
   */
  const ACTION_UNDER_REVIEW = 'under_review';
  const ACTION_APPROVED_PENDING_PAYMENT = 'approved_pending_payment';
  const ACTION_APPROVED = 'approved';
  const ACTION_DENIED = 'denied';
  const ACTION_NOT_FULFILLED = 'not_fulfilled';
  const ACTION_SUSPENDED = 'suspended';
  const ACTION_REMOVED = 'removed';
  const ACTION_EXPIRED = 'expired';
  const ACTION_CANCELLED_BY_MEMBER = 'cancelled_by_member';

  private static $statusIdCache = [];

  /**
   * Per-request cache of each membership's membership_type_id, keyed by
   * membership ID - see getMembershipTypeId().
   *
   * @var array<int,int>
   */
  private static $membershipTypeIdCache = [];

  /**
   * Depth counter for membership updates initiated by this workflow.
   *
   * Core recursively creates/updates inherited memberships during the API
   * call, so a counter protects the whole operation without passing a custom
   * parameter through API validation.
   *
   * @var int
   */
  private static $workflowUpdateDepth = 0;

  /**
   * Per-request cache of each membership's status as of the FIRST time
   * this extension observed it during the current request.
   *
   * A renewal often does two saves on the same membership row in one
   * request - e.g. an intermediate save that lands on Pending while
   * payment is processed, followed moments later by the save that
   * finalizes dates and status. Both go through hook_civicrm_pre/
   * hook_civicrm_alterCalculatedMembershipStatus, but by the second call
   * the database already reflects the intermediate Pending write. Without
   * this cache we'd misread that as "this is a workflow-owned Pending
   * membership" and lock it back to Pending, blocking core's own renewal
   * completion. Caching on first sight captures the true prior status
   * (e.g. Current) before core's own writes can taint it.
   *
   * @var array<int,int>
   */
  private static $observedStatusIdCache = [];

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
   * Load a membership through API4 and fail consistently when it is missing.
   *
   * @param int $membershipId
   * @param array $select
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getMembership($membershipId, array $select = ['*']) {
    $membership = Membership::get(FALSE)
      ->addSelect(...$select)
      ->addWhere('id', '=', $membershipId)
      ->execute()
      ->first();
    if (!$membership) {
      throw new CRM_Core_Exception(E::ts('Membership %1 was not found.', [1 => $membershipId]));
    }
    return $membership;
  }

  /**
   * Update a membership through API4. skipStatusCal is an API3-only control
   * and is intentionally omitted because API4 Membership.update does not run
   * the legacy status calculator.
   *
   * @param array $params
   * @return array
   */
  private static function updateMembership(array $params) {
    $membershipId = $params['id'];
    unset($params['id'], $params['skipStatusCal']);
    return Membership::update(FALSE)
      ->addWhere('id', '=', $membershipId)
      ->setValues($params)
      ->execute()
      ->first();
  }

  /**
   * Retrieve IDs from the legacy MembershipPayment bridge table. CiviCRM 6.16
   * does not expose MembershipPayment as an API4 entity.
   *
   * @param string $filterField
   * @param int $filterValue
   * @param string $returnField
   * @return array<int,int>
   */
  private static function getMembershipPaymentLinkedIds($filterField, $filterValue, $returnField = 'contribution_id') {
    $membershipPayment = new CRM_Member_DAO_MembershipPayment();
    if ($filterField === 'membership_id') {
      $membershipPayment->membership_id = $filterValue;
    }
    else {
      $membershipPayment->contribution_id = $filterValue;
    }
    $membershipPayment->find();
    $ids = [];
    while ($membershipPayment->fetch()) {
      $id = $returnField === 'membership_id'
        ? $membershipPayment->membership_id
        : $membershipPayment->contribution_id;
      $ids[] = (int) $id;
    }
    return $ids;
  }

  /**
   * Disable core's New status while recording its prior state exactly once.
   *
   * The setting is retained while enabled so an enable/disable cycle restores
   * the state that existed before this extension was installed.
   */
  public static function deactivateNewStatus() {
    $newStatusId = self::getStatusIdByName('New');
    if (!$newStatusId) {
      return;
    }

    $settings = Civi::settings();
    if ($settings->get(self::SETTING_NEW_STATUS_WAS_ACTIVE) === NULL) {
      $settings->set(
        self::SETTING_NEW_STATUS_WAS_ACTIVE,
        (bool) CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipStatus', $newStatusId, 'is_active')
      );
    }

    MembershipStatus::update(FALSE)
      ->addWhere('id', '=', $newStatusId)
      ->setValues(['is_active' => FALSE])
      ->execute();
  }

  /**
   * Seed the restoration state for installations upgraded from releases that
   * predate SETTING_NEW_STATUS_WAS_ACTIVE.
   *
   * Those releases always reactivated New on uninstall. Its actual pre-install
   * state was not recorded, so TRUE is the only behavior-compatible fallback.
   */
  public static function seedLegacyNewStatusState() {
    $settings = Civi::settings();
    if ($settings->get(self::SETTING_NEW_STATUS_WAS_ACTIVE) === NULL) {
      $settings->set(self::SETTING_NEW_STATUS_WAS_ACTIVE, TRUE);
    }
  }

  /**
   * Restore the New status to the state it had before this extension changed
   * it, then remove the saved state.
   */
  public static function restoreNewStatus() {
    $settings = Civi::settings();
    $wasActive = $settings->get(self::SETTING_NEW_STATUS_WAS_ACTIVE);
    if ($wasActive === NULL) {
      return;
    }

    $newStatusId = self::getStatusIdByName('New');
    if ($newStatusId) {
      $isActive = CRM_Core_DAO::getFieldValue(
        'CRM_Member_DAO_MembershipStatus',
        $newStatusId,
        'is_active'
      );
      // If an administrator has explicitly reactivated New while the
      // workflow was enabled, preserve that newer choice rather than
      // overwriting it with the install-time value.
      if ($isActive) {
        $settings->revert(self::SETTING_NEW_STATUS_WAS_ACTIVE);
        return;
      }
      MembershipStatus::update(FALSE)
        ->addWhere('id', '=', $newStatusId)
        ->setValues(['is_active' => (bool) $wasActive])
        ->execute();
    }
    $settings->revert(self::SETTING_NEW_STATUS_WAS_ACTIVE);
  }

  /**
   * Fix up a "Not Fullfilled" MembershipStatus name typo that predates
   * managed/MembershipStatus.mgd.php declaring the correctly-spelled
   * "Not Fulfilled" (its `match => ['name']` can only recognize an existing
   * row as the same status if the name actually matches).
   *
   * If nothing named "Not Fulfilled" exists yet, the typo'd row is simply
   * renamed in place - the very next managed-entity reconciliation then
   * updates it (weight, is_admin, etc.) as normal.
   *
   * If a correctly-named row already exists - i.e. reconciliation ran
   * before this upgrade step and created one fresh - the typo'd row is
   * retired instead: any memberships still on it are moved onto the
   * correct status, and the typo'd row is deactivated rather than deleted
   * (existing reports/logs may reference its ID).
   */
  public static function renameNotFulfilledStatusTypo() {
    $typoId = self::getStatusIdByName('Not Fullfilled');
    if (!$typoId) {
      return;
    }
    $correctId = self::getStatusIdByName(self::STATUS_NOT_FULFILLED);

    if (!$correctId) {
      MembershipStatus::update(FALSE)
        ->addWhere('id', '=', $typoId)
        ->setValues(['name' => self::STATUS_NOT_FULFILLED])
        ->execute();
      // getStatusIdByName() cached this as NULL (nothing named "Not
      // Fulfilled" existed yet) just above - drop that now-stale cache
      // entry so a lookup later in this same request re-queries and finds
      // the row we just renamed, instead of getting back the cached NULL.
      unset(self::$statusIdCache[self::STATUS_NOT_FULFILLED]);
      return;
    }

    Membership::update(FALSE)
      ->addWhere('status_id', '=', $typoId)
      ->setValues(['status_id' => $correctId])
      ->execute();
    MembershipStatus::update(FALSE)
      ->addWhere('id', '=', $typoId)
      ->setValues(['is_active' => FALSE])
      ->execute();
  }

  /**
   * Reject direct actions against inherited memberships. Core owns their
   * status through createRelatedMemberships() on the primary membership.
   *
   * @throws \CRM_Core_Exception
   */
  public static function assertPrimaryMembership(array $membership) {
    if (!empty($membership['owner_membership_id'])) {
      throw new CRM_Core_Exception(E::ts('Inherited memberships must be updated through their primary membership.'));
    }
  }

  /**
   * Whether this workflow applies to the given membership type, per the
   * SETTING_MEMBERSHIP_TYPES setting (CRM_Membershipapprovalworkflow_Form_
   * Settings). An empty/unset setting means the workflow applies to every
   * membership type - this is also the pre-upgrade behavior, before this
   * setting existed.
   *
   * @param int|string|NULL $membershipTypeId
   * @return bool
   */
  public static function isMembershipTypeInWorkflow($membershipTypeId) {
    $configuredTypeIds = Civi::settings()->get(self::SETTING_MEMBERSHIP_TYPES);
    if (empty($configuredTypeIds)) {
      return TRUE;
    }
    if (!$membershipTypeId) {
      return FALSE;
    }
    return in_array((int) $membershipTypeId, array_map('intval', (array) $configuredTypeIds), TRUE);
  }

  /**
   * Reject direct actions against a membership whose type this workflow
   * does not apply to - see isMembershipTypeInWorkflow().
   *
   * @throws \CRM_Core_Exception
   */
  public static function assertMembershipTypeInWorkflow(array $membership) {
    if (!self::isMembershipTypeInWorkflow($membership['membership_type_id'] ?? NULL)) {
      throw new CRM_Core_Exception(E::ts('This membership type does not use the approval workflow.'));
    }
  }

  /**
   * The membership's membership_type_id, cached per request - see
   * $membershipTypeIdCache. Used by hooks that only have a membership ID
   * (not the full record) to check isMembershipTypeInWorkflow() against.
   *
   * @param int $membershipId
   * @return int
   */
  private static function getMembershipTypeId($membershipId) {
    if (!array_key_exists($membershipId, self::$membershipTypeIdCache)) {
      self::$membershipTypeIdCache[$membershipId] = (int) CRM_Core_DAO::getFieldValue(
        'CRM_Member_DAO_Membership',
        $membershipId,
        'membership_type_id'
      );
    }
    return self::$membershipTypeIdCache[$membershipId];
  }

  /**
   * Status names this extension owns and no other process should silently
   * move a membership out of.
   */
  public static function protectedStatusNames() {
    return [
      self::STATUS_PENDING,
      self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED,
      self::STATUS_UNDER_REVIEW,
      self::STATUS_APPROVED_PENDING_PAYMENT,
    ];
  }

  /**
   * The approval workflow's status sequence, in display order, for the
   * "where does this fit in the process" help text on the approval form
   * (CRM_Membershipapprovalworkflow_Form_Approve). This is the full happy
   * path, not the set of hops actually available from a given status -
   * see getAllowedActions() for that (which, from "Under Review", offers
   * only one of "Approved/Pending Payment" or "Approved", never both,
   * depending on whether payment is already in) - but this is still the
   * right thing to show staff as "the sequence".
   *
   * Each entry is a "step" - a status name => label map of one or more
   * alternative statuses that occupy the same point in the sequence (e.g.
   * "Pending" vs "Pending Approval/Payment Received", depending on whether
   * payment was received before staff review). A step with more than one
   * alternative is rendered as "A or B" rather than as separate arrows,
   * since a membership is only ever in one of them at a time.
   *
   * The "Approved/Pending Payment" step only applies when payment for this
   * membership hasn't been received yet - if it has (see
   * hasReceivedPayment()), that step is skipped entirely and the sequence
   * goes straight from "Under Review" to "Approved (Current)", since that's
   * genuinely the only sensible next hop for a membership that's already
   * been paid.
   *
   * @param bool $paymentReceived
   *   Whether payment for this membership has already been received - see
   *   hasReceivedPayment().
   * @return array
   *   List of steps, each a status name => label map.
   */
  public static function statusSequence($paymentReceived = FALSE) {
    $steps = [
      [
        self::STATUS_PENDING => E::ts('Pending'),
        self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED => E::ts('Pending Approval/Payment Received'),
      ],
      [self::STATUS_UNDER_REVIEW => E::ts('Under Review')],
    ];
    if (!$paymentReceived) {
      $steps[] = [self::STATUS_APPROVED_PENDING_PAYMENT => E::ts('Approved/Pending Payment')];
    }
    $steps[] = [self::STATUS_CURRENT => E::ts('Approved (Current)')];
    return $steps;
  }

  /**
   * Whether payment for this membership's current pending cycle has
   * already been received, based on the most recently received
   * Contribution linked to it via MembershipPayment.
   *
   * Deliberately looks at the MOST RECENT linked contribution, not "was
   * any linked contribution ever completed" - a membership row is reused
   * across renewal cycles, so an old completed contribution from a prior
   * period would otherwise produce a false positive for the membership's
   * current (still pending) cycle.
   *
   * @param int $membershipId
   * @return bool
   */
  public static function hasReceivedPayment($membershipId) {
    $contributionIds = self::getMembershipPaymentLinkedIds('membership_id', $membershipId);
    if (!$contributionIds) {
      return FALSE;
    }

    $latestContribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id')
      ->addWhere('id', 'IN', $contributionIds)
      ->addOrderBy('receive_date', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();
    if (!$latestContribution) {
      return FALSE;
    }

    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $latestStatusId = $latestContribution['contribution_status_id'];
    return (int) $latestStatusId === (int) $completedStatusId;
  }

  /**
   * The membership's status_id as first observed during this request - see
   * $observedStatusIdCache. Use this (never a fresh getFieldValue() call)
   * anywhere the workflow needs to know whether a membership *was*
   * protected, so a core-internal intermediate save can't masquerade as
   * one.
   */
  private static function getObservedStatusId($membershipId) {
    if (!array_key_exists($membershipId, self::$observedStatusIdCache)) {
      self::$observedStatusIdCache[$membershipId] = (int) CRM_Core_DAO::getFieldValue(
        'CRM_Member_DAO_Membership',
        $membershipId,
        'status_id'
      );
    }
    return self::$observedStatusIdCache[$membershipId];
  }

  /**
   * Seed the observed-status cache from a status_id the caller already has
   * in hand (no DB read), if nothing has been cached for this membership
   * yet. First observation wins - never overwrite.
   *
   * hook_civicrm_alterCalculatedMembershipStatus fires with the
   * membership's pre-write status_id, ahead of CiviCRM's own status
   * changes reaching the database (e.g. fixMembershipStatusBeforeRenew()
   * calls the calculator this hook attaches to, then writes its result
   * straight to the row via a raw DAO save() that bypasses
   * hook_civicrm_pre entirely). Capturing the value here, while it's still
   * trustworthy, is what lets preserveWorkflowStatusOnEdit() - which runs
   * later, once the row may already be mutated - see the true prior
   * status via getObservedStatusId() instead of a stale/tainted DB read.
   */
  private static function cacheObservedStatusId($membershipId, $statusId) {
    if ($membershipId && $statusId && !array_key_exists($membershipId, self::$observedStatusIdCache)) {
      self::$observedStatusIdCache[$membershipId] = (int) $statusId;
    }
  }

  /**
   * Which approval actions are valid from the membership's current status.
   *
   * Mirrors statusSequence()'s treatment of payment: from "Under Review",
   * exactly one of "Approved/Pending Payment" or "Approved" is offered -
   * never both - decided by hasReceivedPayment(): payment already in ->
   * offer "Approved" only (no reason to route through a "pending payment"
   * holding status for money that's already there); no payment yet ->
   * offer "Approved/Pending Payment" only (staff can't activate a
   * membership nothing has been paid for). "Denied" is offered alongside
   * either outcome, since staff can reject an application regardless of
   * whether payment happens to already be in.
   *
   * Beyond the original Pending -> ... -> Current pipeline, a membership
   * can also move on from "Pending"/"Pending Approval/Payment Received"
   * straight to "Current" (skipping review), from "Under Review" to "Not
   * Fulfilled" (in addition to the outcomes above), from "Approved/Pending
   * Payment" back to "Under Review", from "Current" to "Cancelled by
   * Member" or back to "Under Review" (in addition to Suspended / Removed
   * / Expired), and from "Expired" back to "Current".
   *
   * @param string $currentStatusName
   * @param bool $paymentReceived
   *   Whether payment for this membership has already been received - see
   *   hasReceivedPayment().
   * @return array
   *   Action key => label.
   */
  public static function getAllowedActions($currentStatusName, $paymentReceived = FALSE) {
    switch ($currentStatusName) {
      case self::STATUS_PENDING:
      case self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED:
        return [
          self::ACTION_UNDER_REVIEW => E::ts('Under Review'),
          self::ACTION_APPROVED => E::ts('Current'),
        ];

      case self::STATUS_UNDER_REVIEW:
        $actions = $paymentReceived
          ? [self::ACTION_APPROVED => E::ts('Approved')]
          : [self::ACTION_APPROVED_PENDING_PAYMENT => E::ts('Approved/Pending Payment')];
        $actions[self::ACTION_DENIED] = E::ts('Denied');
        $actions[self::ACTION_NOT_FULFILLED] = E::ts('Not Fulfilled');
        return $actions;

      case self::STATUS_APPROVED_PENDING_PAYMENT:
        return [
          self::ACTION_APPROVED => E::ts('Approved'),
          self::ACTION_NOT_FULFILLED => E::ts('Not Fulfilled'),
          self::ACTION_UNDER_REVIEW => E::ts('Under Review'),
        ];

      case self::STATUS_CURRENT:
        return [
          self::ACTION_SUSPENDED => E::ts('Suspended'),
          self::ACTION_REMOVED => E::ts('Removed'),
          self::ACTION_EXPIRED => E::ts('Expired'),
          self::ACTION_CANCELLED_BY_MEMBER => E::ts('Cancelled by Member'),
          self::ACTION_UNDER_REVIEW => E::ts('Under Review'),
        ];

      case self::STATUS_EXPIRED:
        return [self::ACTION_APPROVED => E::ts('Current')];

      default:
        // Grace, Suspended, Removed, Denied, Not Fulfilled, Cancelled by
        // Member, Deceased, etc. - workflow is done.
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
   *   The updated membership (API4 Membership.update result values).
   * @throws \CRM_Core_Exception
   */
  public static function applyAction($membershipId, $action) {
    $membership = self::getMembership($membershipId);
    self::assertPrimaryMembership($membership);
    self::assertMembershipTypeInWorkflow($membership);
    $currentStatusName = self::getStatusNameById($membership['status_id']);
    $allowedActions = self::getAllowedActions($currentStatusName, self::hasReceivedPayment($membershipId));
    if (!array_key_exists($action, $allowedActions)) {
      throw new CRM_Core_Exception(E::ts(
        'The action %1 is not available for a membership with status %2.',
        [1 => $action, 2 => $currentStatusName]
      ));
    }

    $params = [
      'id' => $membershipId,
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
        $params += self::datesForStart($membership, CRM_Utils_Time::date('Y-m-d'));
        break;

      case self::ACTION_DENIED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_DENIED);
        break;

      case self::ACTION_NOT_FULFILLED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_NOT_FULFILLED);
        break;

      case self::ACTION_SUSPENDED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_SUSPENDED);
        break;

      case self::ACTION_REMOVED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_REMOVED);
        break;

      case self::ACTION_EXPIRED:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_EXPIRED);
        break;

      case self::ACTION_CANCELLED_BY_MEMBER:
        $params['status_id'] = self::getStatusIdByName(self::STATUS_CANCELLED_BY_MEMBER);
        break;

      default:
        throw new CRM_Core_Exception(E::ts('Unknown membership approval action: %1', [1 => $action]));
    }

    $result = self::runWorkflowUpdate(static function () use ($params) {
      return self::updateMembership($params);
    });

    if ($action === self::ACTION_UNDER_REVIEW) {
      self::sendUnderReviewNotification($result + $membership);
    }
    elseif ($currentStatusName === self::STATUS_UNDER_REVIEW
      && in_array($action, [self::ACTION_APPROVED_PENDING_PAYMENT, self::ACTION_APPROVED], TRUE)
    ) {
      // Use $result (the just-saved record), not $membership (fetched
      // before the update) - ACTION_APPROVED computes new start/end dates
      // that only $result reflects.
      self::sendUnderReviewApprovedNotification($result + $membership, self::getStatusNameById($params['status_id']));
    }
    elseif ($action === self::ACTION_DENIED) {
      self::sendDeniedNotification($result + $membership);
    }
    elseif ($action === self::ACTION_NOT_FULFILLED) {
      self::sendNotFulfilledNotification($result + $membership);
    }

    return $result;
  }

  /**
   * Email the member when their membership moves from Pending (or Pending
   * Approval/Payment Received) into "Under Review" - the ACTION_UNDER_REVIEW
   * branch of applyAction(). Gated by the
   * SETTING_NOTIFY_UNDER_REVIEW setting - see
   * CRM_Membershipapprovalworkflow_Form_Settings.
   *
   * Never lets a notification failure (missing/suppressed email, mail
   * transport error) block the approval itself - the status change has
   * already been committed by the time this runs.
   */
  private static function sendUnderReviewNotification(array $membership) {
    if (!Civi::settings()->get(self::SETTING_NOTIFY_UNDER_REVIEW)) {
      return;
    }

    $contactId = $membership['contact_id'];
    $toEmail = CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE);
    if (!$toEmail) {
      Civi::log()->info('membershipapprovalworkflow: no deliverable primary email for contact {contactId}, skipping under-review notification for membership {membershipId}.', [
        'contactId' => $contactId,
        'membershipId' => $membership['id'],
      ]);
      return;
    }

    $membershipTypeName = CRM_Core_DAO::getFieldValue(
      'CRM_Member_DAO_MembershipType',
      $membership['membership_type_id'],
      'name'
    );

    try {
      $result = CRM_Core_BAO_MessageTemplate::sendTemplate([
        'workflow' => 'membershipapprovalworkflow_under_review',
        'contactId' => $contactId,
        'toEmail' => $toEmail,
        'tplParams' => [
          'membershipTypeName' => $membershipTypeName,
        ],
      ]);
      if (empty($result[0])) {
        Civi::log()->error('membershipapprovalworkflow: under-review notification could not be sent for membership {membershipId}: {message}', [
          'membershipId' => $membership['id'],
          'message' => $result[4] ?: 'Unknown mail transport error',
        ]);
      }
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to send under-review notification for membership {membershipId}: {message}', [
        'membershipId' => $membership['id'],
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Email the member when staff move their membership out of "Under
   * Review" into either outcome of a completed review - "Approved"
   * (Current) or "Approved/Pending Payment". Both share one message
   * template (workflow_name membershipapprovalworkflow_under_review_approved,
   * registered in managed/MessageTemplate_UnderReviewApproved.mgd.php); the
   * template branches on $newStatusName to word the two outcomes
   * differently.
   *
   * Gated per-outcome by SETTING_NOTIFY_APPROVED_PENDING_PAYMENT /
   * SETTING_NOTIFY_APPROVED - see CRM_Membershipapprovalworkflow_Form_Settings.
   * Both outcomes keep sharing this one template even though they're gated
   * independently; only whether it gets sent at all differs per outcome.
   *
   * Never lets a notification failure (missing/suppressed email, mail
   * transport error) block the approval itself - the status change has
   * already been committed by the time this runs.
   */
  private static function sendUnderReviewApprovedNotification(array $membership, $newStatusName) {
    $settingName = $newStatusName === self::STATUS_APPROVED_PENDING_PAYMENT
      ? self::SETTING_NOTIFY_APPROVED_PENDING_PAYMENT
      : self::SETTING_NOTIFY_APPROVED;
    if (!Civi::settings()->get($settingName)) {
      return;
    }

    $contactId = $membership['contact_id'];
    $toEmail = CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE);
    if (!$toEmail) {
      Civi::log()->info('membershipapprovalworkflow: no deliverable primary email for contact {contactId}, skipping approval notification for membership {membershipId}.', [
        'contactId' => $contactId,
        'membershipId' => $membership['id'],
      ]);
      return;
    }

    $membershipTypeName = CRM_Core_DAO::getFieldValue(
      'CRM_Member_DAO_MembershipType',
      $membership['membership_type_id'],
      'name'
    );

    try {
      $result = CRM_Core_BAO_MessageTemplate::sendTemplate([
        'workflow' => 'membershipapprovalworkflow_under_review_approved',
        'contactId' => $contactId,
        'toEmail' => $toEmail,
        'tplParams' => [
          'membershipTypeName' => $membershipTypeName,
          'newStatusName' => $newStatusName,
          'membershipStartDate' => $membership['start_date'] ?? NULL,
          'membershipEndDate' => $membership['end_date'] ?? NULL,
        ],
      ]);
      if (empty($result[0])) {
        Civi::log()->error('membershipapprovalworkflow: approval notification could not be sent for membership {membershipId}: {message}', [
          'membershipId' => $membership['id'],
          'message' => $result[4] ?: 'Unknown mail transport error',
        ]);
      }
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to send approval notification for membership {membershipId}: {message}', [
        'membershipId' => $membership['id'],
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Email the member when staff move their membership from "Under Review"
   * to "Denied" - the ACTION_DENIED branch of applyAction(). Gated by the
   * SETTING_NOTIFY_DENIED setting - see
   * CRM_Membershipapprovalworkflow_Form_Settings.
   *
   * Never lets a notification failure (missing/suppressed email, mail
   * transport error) block the approval itself - the status change has
   * already been committed by the time this runs.
   */
  private static function sendDeniedNotification(array $membership) {
    if (!Civi::settings()->get(self::SETTING_NOTIFY_DENIED)) {
      return;
    }

    $contactId = $membership['contact_id'];
    $toEmail = CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE);
    if (!$toEmail) {
      Civi::log()->info('membershipapprovalworkflow: no deliverable primary email for contact {contactId}, skipping denied notification for membership {membershipId}.', [
        'contactId' => $contactId,
        'membershipId' => $membership['id'],
      ]);
      return;
    }

    $membershipTypeName = CRM_Core_DAO::getFieldValue(
      'CRM_Member_DAO_MembershipType',
      $membership['membership_type_id'],
      'name'
    );

    try {
      $result = CRM_Core_BAO_MessageTemplate::sendTemplate([
        'workflow' => 'membershipapprovalworkflow_denied',
        'contactId' => $contactId,
        'toEmail' => $toEmail,
        'tplParams' => [
          'membershipTypeName' => $membershipTypeName,
        ],
      ]);
      if (empty($result[0])) {
        Civi::log()->error('membershipapprovalworkflow: denied notification could not be sent for membership {membershipId}: {message}', [
          'membershipId' => $membership['id'],
          'message' => $result[4] ?: 'Unknown mail transport error',
        ]);
      }
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to send denied notification for membership {membershipId}: {message}', [
        'membershipId' => $membership['id'],
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Email the member when staff move their membership from "Approved/
   * Pending Payment" to "Not Fulfilled" - the ACTION_NOT_FULFILLED branch
   * of applyAction(). Gated by the SETTING_NOTIFY_NOT_FULFILLED setting -
   * see CRM_Membershipapprovalworkflow_Form_Settings.
   *
   * Never lets a notification failure (missing/suppressed email, mail
   * transport error) block the approval itself - the status change has
   * already been committed by the time this runs.
   */
  private static function sendNotFulfilledNotification(array $membership) {
    if (!Civi::settings()->get(self::SETTING_NOTIFY_NOT_FULFILLED)) {
      return;
    }

    $contactId = $membership['contact_id'];
    $toEmail = CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE);
    if (!$toEmail) {
      Civi::log()->info('membershipapprovalworkflow: no deliverable primary email for contact {contactId}, skipping not-fulfilled notification for membership {membershipId}.', [
        'contactId' => $contactId,
        'membershipId' => $membership['id'],
      ]);
      return;
    }

    $membershipTypeName = CRM_Core_DAO::getFieldValue(
      'CRM_Member_DAO_MembershipType',
      $membership['membership_type_id'],
      'name'
    );

    try {
      $result = CRM_Core_BAO_MessageTemplate::sendTemplate([
        'workflow' => 'membershipapprovalworkflow_not_fulfilled',
        'contactId' => $contactId,
        'toEmail' => $toEmail,
        'tplParams' => [
          'membershipTypeName' => $membershipTypeName,
        ],
      ]);
      if (empty($result[0])) {
        Civi::log()->error('membershipapprovalworkflow: not-fulfilled notification could not be sent for membership {membershipId}: {message}', [
          'membershipId' => $membership['id'],
          'message' => $result[4] ?: 'Unknown mail transport error',
        ]);
      }
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to send not-fulfilled notification for membership {membershipId}: {message}', [
        'membershipId' => $membership['id'],
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Move an "Approved/Pending Payment" membership to Current once its
   * linked payment is received, per requirement 2D / 5.
   *
   * Never lets a failure propagate - this is reachable from
   * hook_civicrm_post (via handleContributionCompleted()), and an
   * uncaught exception there would surface as a fatal error to the member
   * immediately after their payment was actually captured and the
   * contribution already saved as Completed.
   *
   * @param int $membershipId
   * @param string $paymentDate
   *   Y-m-d (or any CRM_Utils_Date-parseable) date the payment was received.
   * @return array|NULL
   *   The updated membership, or NULL if the update failed (logged).
   */
  public static function markCurrentOnPayment($membershipId, $paymentDate) {
    try {
      $membership = self::getMembership($membershipId);
      $params = [
        'id' => $membershipId,
        'contact_id' => $membership['contact_id'],
        'is_override' => 0,
        'status_override_end_date' => '',
        'status_id' => self::getStatusIdByName(self::STATUS_CURRENT),
      ];
      $params += self::datesForStart($membership, $paymentDate);
      return self::runWorkflowUpdate(static function () use ($params) {
        return self::updateMembership($params);
      });
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to mark membership {membershipId} as Current on payment {paymentDate}: {message}', [
        'membershipId' => $membershipId,
        'paymentDate' => $paymentDate,
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Move a freshly created "Pending" membership to "Pending Approval/
   * Payment Received" once its linked payment is received while it is
   * still awaiting staff review - i.e. the member paid at signup instead
   * of choosing pay-later. Only the status changes; there is no start/end
   * date yet since the membership still hasn't been approved.
   *
   * Never lets a failure propagate - see markCurrentOnPayment(); this is
   * reachable from the same hook_civicrm_post call chain.
   *
   * @param int $membershipId
   * @return array|NULL
   *   The updated membership, or NULL if the update failed (logged).
   */
  public static function markPendingApprovalPaymentReceived($membershipId) {
    try {
      $membership = self::getMembership($membershipId);
      $params = [
        'id' => $membershipId,
        'contact_id' => $membership['contact_id'],
        'is_override' => 0,
        'status_override_end_date' => '',
        'status_id' => self::getStatusIdByName(self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED),
      ];
      return self::runWorkflowUpdate(static function () use ($params) {
        return self::updateMembership($params);
      });
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to mark membership {membershipId} as Pending Approval/Payment Received: {message}', [
        'membershipId' => $membershipId,
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * hook_civicrm_pre callback (Membership, op=create) - force every brand
   * new, non-inherited membership to start as Pending, per requirement 1,
   * regardless of whether it was submitted with immediate payment or
   * pay-later. Staff must then move it forward via the approval dropdown.
   *
   * Exception: if the contact already holds a Current/Grace membership of
   * this type, this "create" is really a renewal landing in a new row
   * (e.g. a type change) rather than a fresh signup - leave it to core's
   * normal renewal handling instead of resetting it to Pending.
   *
   * Membership types left out of SETTING_MEMBERSHIP_TYPES skip this
   * workflow entirely - see isMembershipTypeInWorkflow().
   */
  public static function forcePendingOnCreate(array &$params) {
    // Inherited memberships are managed by core's own
    // createRelatedMemberships(), which syncs status from the owner
    // membership and already sets skipStatusCal - leave them alone.
    if (!empty($params['owner_membership_id'])) {
      return;
    }
    if (!self::isMembershipTypeInWorkflow($params['membership_type_id'] ?? NULL)) {
      return;
    }
    if (self::isRenewalOfActiveMembership($params)) {
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
   * True if the contact already holds a Current or Grace membership of the
   * type being created - i.e. this new row is a renewal of an already
   * active membership, not a fresh signup, so the approval workflow
   * shouldn't touch it.
   */
  private static function isRenewalOfActiveMembership(array $params) {
    if (empty($params['contact_id']) || empty($params['membership_type_id'])) {
      return FALSE;
    }
    $activeStatusIds = array_values(array_filter([
      self::getStatusIdByName(self::STATUS_CURRENT),
      self::getStatusIdByName(self::STATUS_GRACE),
    ]));
    if (!$activeStatusIds) {
      return FALSE;
    }
    return (bool) Membership::get(FALSE)
      ->addWhere('contact_id', '=', $params['contact_id'])
      ->addWhere('membership_type_id', '=', $params['membership_type_id'])
      ->addWhere('status_id', 'IN', $activeStatusIds)
      ->selectRowCount()
      ->execute()
      ->count();
  }

  /**
   * Prevent an ordinary edit/API call from moving a workflow-controlled
   * membership out of its current status.
   *
   * Current and Grace are not protected statuses, so a renewal edit against
   * a membership already in one of those states (the normal case - core
   * extends the existing row's end_date) passes through untouched here;
   * only the statuses in protectedStatusNames() are pinned.
   *
   * "Already in" is judged from getObservedStatusId(), not a fresh
   * database read, so a renewal's own intermediate Pending save (see that
   * method's docblock) can't be mistaken for a workflow-owned Pending.
   *
   * Membership types left out of SETTING_MEMBERSHIP_TYPES skip this
   * workflow entirely - see isMembershipTypeInWorkflow().
   */
  public static function preserveWorkflowStatusOnEdit($membershipId, array &$params) {
    if (!$membershipId || self::$workflowUpdateDepth > 0) {
      return;
    }
    if (!self::isMembershipTypeInWorkflow(self::getMembershipTypeId($membershipId))) {
      return;
    }

    $currentStatusId = self::getObservedStatusId($membershipId);
    $currentStatusName = self::getStatusNameById($currentStatusId);

    if (!in_array($currentStatusName, self::protectedStatusNames(), TRUE)) {
      return;
    }
    $params['status_id'] = $currentStatusId;
    $params['skipStatusCal'] = TRUE;
    $params['is_override'] = 0;
    $params['status_override_end_date'] = '';
  }

  /**
   * hook_civicrm_post callback (Contribution) - when a contribution linked
   * to a membership becomes completed:
   *  - if that membership is "Approved/Pending Payment", move it to
   *    Current with start date = payment date (requirements 2D / 5).
   *  - if that membership is still the initial "Pending" (i.e. payment
   *    was made at signup rather than pay-later, before staff have
   *    reviewed it) - move it to "Pending Approval/Payment Received" so
   *    staff can see payment has already been received. This applies
   *    equally to a renewal of a previously Expired membership: it still
   *    goes through the full review process rather than being activated
   *    directly.
   *    A pay-later membership whose contribution is never completed is
   *    unaffected and stays Pending, per existing behavior.
   *
   * A linked membership whose type is left out of SETTING_MEMBERSHIP_TYPES
   * is skipped - see isMembershipTypeInWorkflow().
   */
  public static function handleContributionCompleted($contributionId) {
    $contribution = Contribution::get(FALSE)
      ->addSelect('receive_date')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();
    if (!$contribution) {
      throw new CRM_Core_Exception(E::ts('Contribution %1 was not found.', [1 => $contributionId]));
    }
    $paymentDate = $contribution['receive_date'];

    $membershipIds = self::getMembershipPaymentLinkedIds('contribution_id', $contributionId, 'membership_id');
    foreach ($membershipIds as $membershipId) {
      if (!self::isMembershipTypeInWorkflow(self::getMembershipTypeId($membershipId))) {
        continue;
      }
      $statusId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $membershipId, 'status_id');
      $statusName = self::getStatusNameById($statusId);
      if ($statusName === self::STATUS_APPROVED_PENDING_PAYMENT) {
        self::markCurrentOnPayment($membershipId, $paymentDate);
      }
      elseif ($statusName === self::STATUS_PENDING) {
        self::markPendingApprovalPaymentReceived($membershipId);
      }
    }
  }

  /**
   * hook_civicrm_alterCalculatedMembershipStatus callback.
   *
   * Two jobs:
   *
   * 1. Keep protectedStatusNames() memberships in place. They are only ever meant to change via the
   *    approval dropdown or the payment hook above, never via CiviCRM's
   *    date-based status calculator (the nightly job, membership
   *    create/edit, or the renewal/order-complete flow all funnel through
   *    here).
   *
   * 2. Block the calculator from ever demoting an already Current/Grace
   *    membership to Pending. CRM_Member_BAO_Membership::
   *    fixMembershipStatusBeforeRenew() (called from
   *    Civi\Membership\OrderCompleteSubscriber during renewal payment
   *    completion) calls this calculator and then writes its result
   *    straight to the database via a raw DAO save() - which does NOT
   *    fire hook_civicrm_pre, so preserveWorkflowStatusOnEdit() never gets
   *    a chance to stop it. This hook is the only interception point for
   *    that write. A genuinely active membership should never calculate
   *    to Pending (it's an is_admin status, excluded from date-based
   *    calculation by core's own excludeIsAdmin flag on this code path),
   *    so seeing it here means core's renewal bookkeeping produced a
   *    bogus target - restore the membership's observed status instead of
   *    letting that persist.
   *
   * Membership types left out of SETTING_MEMBERSHIP_TYPES skip both jobs -
   * see isMembershipTypeInWorkflow().
   */
  public static function preserveProtectedStatus(array &$membershipStatus, array $membership) {
    $membershipId = $membership['id'] ?? NULL;
    if (!$membershipId) {
      return;
    }
    $membershipTypeId = $membership['membership_type_id'] ?? self::getMembershipTypeId($membershipId);
    if (!self::isMembershipTypeInWorkflow($membershipTypeId)) {
      return;
    }
    // $membership['status_id'] is the row's status as of just before this
    // calculation - seed the cache from it now, before any core-internal
    // write can taint what a later getObservedStatusId() read would see.
    self::cacheObservedStatusId($membershipId, $membership['status_id'] ?? NULL);

    $observedStatusId = self::getObservedStatusId($membershipId);
    $observedStatusName = self::getStatusNameById($observedStatusId);

    if (in_array($observedStatusName, self::protectedStatusNames(), TRUE)) {
      $membershipStatus = ['id' => $observedStatusId, 'name' => $observedStatusName];
      return;
    }

    $calculatedStatusName = $membershipStatus['name'] ?? NULL;
    if ($calculatedStatusName === self::STATUS_PENDING && $observedStatusName !== self::STATUS_PENDING) {
      $membershipStatus = ['id' => $observedStatusId, 'name' => $observedStatusName];
    }
  }

  /**
   * Run a membership update which is authorized by this workflow.
   *
   * @template T
   * @param callable(): T $callback
   * @return T
   */
  private static function runWorkflowUpdate(callable $callback) {
    self::$workflowUpdateDepth++;
    try {
      return $callback();
    }
    finally {
      self::$workflowUpdateDepth--;
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
