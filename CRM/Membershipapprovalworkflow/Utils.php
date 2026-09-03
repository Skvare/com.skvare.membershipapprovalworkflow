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
  const STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED = 'Pending Approval/Payment Received';
  const STATUS_CURRENT = 'Current';
  const STATUS_GRACE = 'Grace';
  const STATUS_NEW = 'New';
  const STATUS_EXPIRED = 'Expired';

  /**
   * Action keys used by the approval dropdown, in display order.
   */
  const ACTION_UNDER_REVIEW = 'under_review';
  const ACTION_APPROVED_PENDING_PAYMENT = 'approved_pending_payment';
  const ACTION_APPROVED = 'approved';

  private static $statusIdCache = [];

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
    $membershipPayments = civicrm_api3('MembershipPayment', 'get', [
      'membership_id' => $membershipId,
      'return' => ['contribution_id'],
    ])['values'];
    if (!$membershipPayments) {
      return FALSE;
    }

    $contributionIds = array_column($membershipPayments, 'contribution_id');
    $latestContribution = civicrm_api3('Contribution', 'get', [
      'id' => ['IN' => $contributionIds],
      'return' => ['contribution_status_id'],
      'options' => ['sort' => 'receive_date DESC', 'limit' => 1],
    ])['values'];
    if (!$latestContribution) {
      return FALSE;
    }

    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $latestStatusId = reset($latestContribution)['contribution_status_id'];
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
   * membership nothing has been paid for).
   *
   * @param string $currentStatusName
   * @param bool $paymentReceived
   *   Whether payment for this membership has already been received - see
   *   hasReceivedPayment().
   * @return array
   *   Action key => label.
   */
  public static function getAllowedActions($currentStatusName, $paymentReceived = FALSE) {
    $actions = [
      self::ACTION_UNDER_REVIEW => E::ts('Under Review'),
      self::ACTION_APPROVED_PENDING_PAYMENT => E::ts('Approved/Pending Payment'),
      self::ACTION_APPROVED => E::ts('Approved'),
    ];

    switch ($currentStatusName) {
      case self::STATUS_PENDING:
      case self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED:
        unset($actions[self::ACTION_APPROVED_PENDING_PAYMENT]);
        unset($actions[self::ACTION_APPROVED]);
        return $actions;

      case self::STATUS_UNDER_REVIEW:
        unset($actions[self::ACTION_UNDER_REVIEW]);
        if ($paymentReceived) {
          unset($actions[self::ACTION_APPROVED_PENDING_PAYMENT]);
        }
        else {
          unset($actions[self::ACTION_APPROVED]);
        }
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
        $params += self::datesForStart($membership, CRM_Utils_Time::date('Y-m-d'));
        break;

      default:
        throw new CRM_Core_Exception(E::ts('Unknown membership approval action: %1', [1 => $action]));
    }

    $result = self::runWorkflowUpdate(static function () use ($params, $membershipId) {
      return civicrm_api3('Membership', 'create', $params)['values'][$membershipId] ?? [];
    });

    if ($currentStatusName === self::STATUS_UNDER_REVIEW
      && in_array($action, [self::ACTION_APPROVED_PENDING_PAYMENT, self::ACTION_APPROVED], TRUE)
    ) {
      // Use $result (the just-saved record), not $membership (fetched
      // before the update) - ACTION_APPROVED computes new start/end dates
      // that only $result reflects.
      self::sendUnderReviewApprovedNotification($result + $membership, self::getStatusNameById($params['status_id']));
    }

    return $result;
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
   * Never lets a notification failure (missing/suppressed email, mail
   * transport error) block the approval itself - the status change has
   * already been committed by the time this runs.
   */
  private static function sendUnderReviewApprovedNotification(array $membership, $newStatusName) {
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
      CRM_Core_BAO_MessageTemplate::sendTemplate([
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
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->error('membershipapprovalworkflow: failed to send approval notification for membership {membershipId}: {message}', [
        'membershipId' => $membership['id'],
        'message' => $e->getMessage(),
      ]);
    }
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
      'contact_id' => $membership['contact_id'],
      'skipStatusCal' => TRUE,
      'is_override' => 0,
      'status_override_end_date' => '',
      'status_id' => self::getStatusIdByName(self::STATUS_CURRENT),
    ];
    $params += self::datesForStart($membership, $paymentDate);
    self::runWorkflowUpdate(static function () use ($params) {
      try {
        $result = civicrm_api3('Membership', 'create', $params);
      }
      catch (CRM_Core_Exception $e) {
        Civi::log()->error('membershipapprovalworkflow: failed to mark membership {membershipId} as Current on payment {paymentDate}: {message}', [
          'membershipId' => $params['id'],
          'paymentDate' => $params['start_date'],
          'message' => $e->getMessage(),
        ]);
      }
    });
  }

  /**
   * Move a freshly created "Pending" membership to "Pending Approval/
   * Payment Received" once its linked payment is received while it is
   * still awaiting staff review - i.e. the member paid at signup instead
   * of choosing pay-later. Only the status changes; there is no start/end
   * date yet since the membership still hasn't been approved.
   *
   * @param int $membershipId
   */
  public static function markPendingApprovalPaymentReceived($membershipId) {
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membershipId]);
    $params = [
      'id' => $membershipId,
      'contact_id' => $membership['contact_id'],
      'skipStatusCal' => TRUE,
      'is_override' => 0,
      'status_override_end_date' => '',
      'status_id' => self::getStatusIdByName(self::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED),
    ];
    self::runWorkflowUpdate(static function () use ($params) {
      try {
        civicrm_api3('Membership', 'create', $params);
      }
      catch (CRM_Core_Exception $e) {
        Civi::log()->error('membershipapprovalworkflow: failed to mark membership {membershipId} as Pending Approval/Payment Received: {message}', [
          'membershipId' => $params['id'],
          'message' => $e->getMessage(),
        ]);
      }
    });
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
   */
  public static function forcePendingOnCreate(array &$params) {
    // Inherited memberships are managed by core's own
    // createRelatedMemberships(), which syncs status from the owner
    // membership and already sets skipStatusCal - leave them alone.
    if (!empty($params['owner_membership_id'])) {
      return;
    }
    //if (self::isRenewalOfActiveMembership($params)) {
    //  return;
    //}
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
    return (bool) civicrm_api3('Membership', 'getcount', [
      'contact_id' => $params['contact_id'],
      'membership_type_id' => $params['membership_type_id'],
      'status_id' => ['IN' => $activeStatusIds],
    ]);
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
   */
  public static function preserveWorkflowStatusOnEdit($membershipId, array &$params) {
    if (!$membershipId || self::$workflowUpdateDepth > 0) {
      return;
    }

    $currentStatusId = self::getObservedStatusId($membershipId);
    $currentStatusName = self::getStatusNameById($currentStatusId);

    if (!in_array($currentStatusName, self::protectedStatusNames(), TRUE)) {
      return;
    }
    if ($currentStatusId !== ($params['status_id'] ?? NULL) && $currentStatusId == self::getStatusIdByName(self::STATUS_CURRENT)) {
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
   *    reviewed it):
   *    - and it's a renewal of an Expired membership of the same type for
   *      the same contact (see isRenewalOfExpiredMembership()) - move it
   *      straight to Current. The contact already held this membership
   *      before; there's nothing new here for staff to review.
   *    - otherwise (a genuinely new application) - move it to "Pending
   *      Approval/Payment Received" so staff can see payment has already
   *      been received.
   *    A pay-later membership whose contribution is never completed is
   *    unaffected and stays Pending, per existing behavior.
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
      $statusName = self::getStatusNameById($statusId);
      if ($statusName === self::STATUS_APPROVED_PENDING_PAYMENT) {
        self::markCurrentOnPayment($membershipId, $paymentDate);
      }
      elseif ($statusName === self::STATUS_PENDING) {
        if (self::isRenewalOfExpiredMembership($membershipId)) {
          // Core creates a brand-new membership row for a renewal of an
          // Expired membership (rather than editing the old row), so this
          // lands here indistinguishable from a fresh signup at first
          // glance. It isn't one - the contact already held this
          // membership type before, so there's nothing new to review.
          // Skip the approval queue entirely and activate it directly.
          self::markCurrentOnPayment($membershipId, $paymentDate);
        }
        else {
          self::markPendingApprovalPaymentReceived($membershipId);
        }
      }
    }
  }

  /**
   * True if this membership is a renewal of an Expired membership of the
   * same type for the same contact - i.e. the contact already held this
   * membership type before, just not continuously, rather than this being
   * a genuinely new application.
   *
   * Needed because core (as configured on this site) creates a brand-new
   * membership row for a renewal of an Expired membership instead of
   * editing the old row, so `handleContributionCompleted()` can't tell a
   * true fresh signup apart from this kind of renewal just by looking at
   * the membership's own `id`/status history - it has to check for a
   * sibling row.
   *
   * @param int $membershipId
   * @return bool
   */
  private static function isRenewalOfExpiredMembership($membershipId) {
    $expiredStatusId = self::getStatusIdByName(self::STATUS_EXPIRED);
    if (!$expiredStatusId) {
      return FALSE;
    }

    $membership = civicrm_api3('Membership', 'getsingle', [
      'id' => $membershipId,
      'return' => ['contact_id', 'membership_type_id'],
    ]);

    return (bool) civicrm_api3('Membership', 'getcount', [
      'id' => ['!=' => $membershipId],
      'contact_id' => $membership['contact_id'],
      'membership_type_id' => $membership['membership_type_id'],
      'status_id' => $expiredStatusId,
    ]);
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
   */
  public static function preserveProtectedStatus(array &$membershipStatus, array $membership) {
    $membershipId = $membership['id'] ?? NULL;
    if (!$membershipId) {
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
