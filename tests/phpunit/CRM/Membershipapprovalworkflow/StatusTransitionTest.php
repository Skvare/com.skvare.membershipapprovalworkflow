<?php

use Civi\Api4\Membership;
use Civi\Api4\PriceFieldValue;
use CRM_Membershipapprovalworkflow_Utils as Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Walks memberships through the approval workflow, asserting after each step.
 *
 * Staff actions go through Utils::applyAction() - what the Approve form
 * calls - and payments through core's Order/Payment APIs, so core's own
 * order-completion writes run against this extension's status guards. Each
 * step starts from cleared Utils caches, as a separate request would.
 *
 * @group headless
 */
#[Group('headless')]
class CRM_Membershipapprovalworkflow_StatusTransitionTest extends CRM_Membershipapprovalworkflow_Base {

  /**
   * Date the tests run on; approving to Current starts the membership today.
   */
  private const TODAY = '2026-03-10';

  /**
   * Freezes the clock so start/end dates can be asserted exactly.
   */
  protected function setUp(): void {
    parent::setUp();
    CRM_Utils_Time::setTime(self::TODAY . ' 10:00:00');
  }

  public function testPayLaterApplicationBecomesCurrentWhenPaid(): void {
    ['membership_id' => $membershipId, 'contribution_id' => $contributionId] = $this->createPendingMembershipOrder();
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING);
    $this->assertMembershipDates($membershipId, NULL, NULL);
    $this->assertAllowedActions($membershipId, [Utils::ACTION_UNDER_REVIEW, Utils::ACTION_APPROVED]);

    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);
    // Nothing paid yet, so approval has to wait for payment.
    $this->assertAllowedActions($membershipId, [
      Utils::ACTION_APPROVED_PENDING_PAYMENT,
      Utils::ACTION_DENIED,
      Utils::ACTION_NOT_FULFILLED,
    ]);

    $this->applyAction($membershipId, Utils::ACTION_APPROVED_PENDING_PAYMENT);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_APPROVED_PENDING_PAYMENT);
    $this->assertMembershipDates($membershipId, NULL, NULL);
    $this->assertAllowedActions($membershipId, [
      Utils::ACTION_APPROVED,
      Utils::ACTION_NOT_FULFILLED,
      Utils::ACTION_UNDER_REVIEW,
    ]);

    $this->payOrder($contributionId, '2026-03-20');
    $this->assertMembershipStatus($membershipId, Utils::STATUS_CURRENT);
    // Starts on the payment date, not the approval date.
    $this->assertMembershipDates($membershipId, '2026-03-20', '2027-03-19');
  }

  public function testApplicationPaidBeforeReviewIsApprovedStraightToCurrent(): void {
    ['membership_id' => $membershipId, 'contribution_id' => $contributionId] = $this->createPendingMembershipOrder();
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING);

    $this->payOrder($contributionId, self::TODAY);
    // Paid but not reviewed - payment alone doesn't activate it.
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING_APPROVAL_PAYMENT_RECEIVED);
    $this->assertMembershipDates($membershipId, NULL, NULL);
    $this->assertAllowedActions($membershipId, [Utils::ACTION_UNDER_REVIEW, Utils::ACTION_APPROVED]);

    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);
    // Already paid, so there's no pending-payment step to offer.
    $this->assertAllowedActions($membershipId, [
      Utils::ACTION_APPROVED,
      Utils::ACTION_DENIED,
      Utils::ACTION_NOT_FULFILLED,
    ]);

    $this->applyAction($membershipId, Utils::ACTION_APPROVED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_CURRENT);
    $this->assertMembershipDates($membershipId, self::TODAY, '2027-03-09');
  }

  public function testPendingApplicationCanBeApprovedWithoutReview(): void {
    $membershipId = $this->createTestMembership();
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING);

    $this->applyAction($membershipId, Utils::ACTION_APPROVED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_CURRENT);
    $this->assertMembershipDates($membershipId, self::TODAY, '2027-03-09');
  }

  public function testUnderReviewApplicationCanBeDenied(): void {
    $membershipId = $this->createTestMembership();
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING);

    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);

    $this->applyAction($membershipId, Utils::ACTION_DENIED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_DENIED);
    $this->assertAllowedActions($membershipId, []);
  }

  public function testApprovedPendingPaymentCanReturnToReviewOrBeNotFulfilled(): void {
    $membershipId = $this->createTestMembership();
    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);

    $this->applyAction($membershipId, Utils::ACTION_APPROVED_PENDING_PAYMENT);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_APPROVED_PENDING_PAYMENT);

    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);

    $this->applyAction($membershipId, Utils::ACTION_APPROVED_PENDING_PAYMENT);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_APPROVED_PENDING_PAYMENT);

    $this->applyAction($membershipId, Utils::ACTION_NOT_FULFILLED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_NOT_FULFILLED);
    $this->assertAllowedActions($membershipId, []);
  }

  public function testUnderReviewApplicationCanBeNotFulfilled(): void {
    $membershipId = $this->createTestMembership();
    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);

    $this->applyAction($membershipId, Utils::ACTION_NOT_FULFILLED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_NOT_FULFILLED);
    $this->assertAllowedActions($membershipId, []);
  }

  public function testCurrentMembershipCanBeSentBackToReview(): void {
    $membershipId = $this->createCurrentMembership();

    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);
    $this->assertAllowedActions($membershipId, [
      Utils::ACTION_APPROVED_PENDING_PAYMENT,
      Utils::ACTION_DENIED,
      Utils::ACTION_NOT_FULFILLED,
    ]);
  }

  public function testExpiredMembershipIsReactivatedWithNewDatesAndOriginalJoinDate(): void {
    $membershipId = $this->createCurrentMembership();
    $joinDate = $this->getMembershipField($membershipId, 'join_date');

    $this->applyAction($membershipId, Utils::ACTION_EXPIRED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_EXPIRED);
    $this->assertAllowedActions($membershipId, [Utils::ACTION_APPROVED]);

    CRM_Utils_Time::setTime('2027-04-01 10:00:00');
    $this->applyAction($membershipId, Utils::ACTION_APPROVED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_CURRENT);
    $this->assertMembershipDates($membershipId, '2027-04-01', '2028-03-31');
    $this->assertSame($joinDate, $this->getMembershipField($membershipId, 'join_date'));
  }

  /**
   * Leaving Current by suspension, removal or cancellation ends the workflow.
   *
   * @dataProvider finalExitFromCurrentProvider
   */
  #[DataProvider('finalExitFromCurrentProvider')]
  public function testCurrentMembershipExitIsFinal(string $action, string $expectedStatusName): void {
    $membershipId = $this->createCurrentMembership();

    $this->applyAction($membershipId, $action);
    $this->assertMembershipStatus($membershipId, $expectedStatusName);
    $this->assertAllowedActions($membershipId, []);
  }

  /**
   * Actions from Current that end the workflow.
   *
   * @return array
   *   Action => expected status name.
   */
  public static function finalExitFromCurrentProvider(): array {
    return [
      'suspended' => [Utils::ACTION_SUSPENDED, Utils::STATUS_SUSPENDED],
      'removed' => [Utils::ACTION_REMOVED, Utils::STATUS_REMOVED],
      'cancelled by member' => [Utils::ACTION_CANCELLED_BY_MEMBER, Utils::STATUS_CANCELLED_BY_MEMBER],
    ];
  }

  public function testActionNotAllowedFromCurrentStatusIsRejected(): void {
    $membershipId = $this->createTestMembership();

    try {
      $this->applyAction($membershipId, Utils::ACTION_DENIED);
      $this->fail('Denying a Pending membership should be rejected.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('is not available', $e->getMessage());
    }
    $this->assertMembershipStatus($membershipId, Utils::STATUS_PENDING);
  }

  public function testActionOnMembershipTypeOutsideWorkflowIsRejected(): void {
    $membershipId = $this->createTestMembership($this->createMembershipTypeOutsideWorkflow());
    $statusBefore = $this->getMembershipField($membershipId, 'status_id:name');

    try {
      $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
      $this->fail('Membership types outside the workflow should be rejected.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('does not use the approval workflow', $e->getMessage());
    }
    $this->assertMembershipStatus($membershipId, $statusBefore);
  }

  public function testOrdinaryEditCannotMoveProtectedStatus(): void {
    $membershipId = $this->createTestMembership();
    $this->applyAction($membershipId, Utils::ACTION_UNDER_REVIEW);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);

    $this->resetWorkflowCaches();
    Membership::update(FALSE)
      ->addWhere('id', '=', $membershipId)
      ->addValue('status_id:name', Utils::STATUS_CURRENT)
      ->addValue('is_override', TRUE)
      ->execute();
    $this->assertMembershipStatus($membershipId, Utils::STATUS_UNDER_REVIEW);
    $this->assertFalse($this->getMembershipField($membershipId, 'is_override'));
  }

  public function testActionClearsStatusOverride(): void {
    $membershipId = $this->createCurrentMembership();
    // Current isn't protected, so staff may set a native status override.
    Membership::update(FALSE)
      ->addWhere('id', '=', $membershipId)
      ->addValue('is_override', TRUE)
      ->execute();
    $this->assertTrue($this->getMembershipField($membershipId, 'is_override'));

    $this->applyAction($membershipId, Utils::ACTION_SUSPENDED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_SUSPENDED);
    $this->assertFalse($this->getMembershipField($membershipId, 'is_override'));
  }

  /**
   * Applies a staff action in a fresh "request", like the Approve form.
   *
   * @param int $membershipId
   *   The membership ID.
   * @param string $action
   *   One of the Utils::ACTION_* constants.
   */
  private function applyAction(int $membershipId, string $action): void {
    $this->resetWorkflowCaches();
    Utils::applyAction($membershipId, $action);
  }

  /**
   * Creates a workflow membership approved straight to Current today.
   *
   * @return int
   *   The membership ID.
   */
  private function createCurrentMembership(): int {
    $membershipId = $this->createTestMembership();
    $this->applyAction($membershipId, Utils::ACTION_APPROVED);
    $this->assertMembershipStatus($membershipId, Utils::STATUS_CURRENT);
    return $membershipId;
  }

  /**
   * Creates a pay-later order for a workflow membership, as a signup would.
   *
   * @return array
   *   With keys membership_id and contribution_id.
   */
  private function createPendingMembershipOrder(): array {
    $contactId = $this->individualCreate();
    $priceFieldValue = PriceFieldValue::get(FALSE)
      ->addSelect('id', 'price_field_id', 'label', 'amount')
      ->addWhere('membership_type_id', '=', $this->workflowMembershipTypeId)
      ->execute()
      ->single();

    $order = $this->callAPISuccess('Order', 'create', [
      'contact_id' => $contactId,
      'financial_type_id' => 'Member Dues',
      'receive_date' => self::TODAY,
      'line_items' => [
        [
          'params' => [
            'contact_id' => $contactId,
            'membership_type_id' => $this->workflowMembershipTypeId,
            'source' => 'Membership signup',
          ],
          'line_item' => [
            [
              'entity_table' => 'civicrm_membership',
              'price_field_id' => $priceFieldValue['price_field_id'],
              'price_field_value_id' => $priceFieldValue['id'],
              'label' => $priceFieldValue['label'],
              'qty' => 1,
              'unit_price' => $priceFieldValue['amount'],
              'line_total' => $priceFieldValue['amount'],
              'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Member Dues'),
            ],
          ],
        ],
      ],
    ]);

    foreach ($order['values'][$order['id']]['line_item'] as $line) {
      if ($line['entity_table'] === 'civicrm_membership') {
        return ['membership_id' => (int) $line['entity_id'], 'contribution_id' => (int) $order['id']];
      }
    }
    $this->fail('Order created no membership.');
  }

  /**
   * Pays an order in full, in a fresh "request".
   *
   * @param int $contributionId
   *   The order's contribution ID.
   * @param string $date
   *   Payment date, Y-m-d.
   */
  private function payOrder(int $contributionId, string $date): void {
    $this->resetWorkflowCaches();
    $this->callAPISuccess('Payment', 'create', [
      'contribution_id' => $contributionId,
      'total_amount' => $this->callAPISuccessGetValue('Contribution', [
        'id' => $contributionId,
        'return' => 'total_amount',
      ]),
      'trxn_date' => $date,
      'payment_instrument_id' => 'Check',
    ]);
  }

  /**
   * Asserts the actions the Approve form would offer staff next.
   *
   * @param int $membershipId
   *   The membership ID.
   * @param string[] $expectedActions
   *   Utils::ACTION_* constants, in the order offered.
   */
  private function assertAllowedActions(int $membershipId, array $expectedActions): void {
    $this->resetWorkflowCaches();
    $actions = Utils::getAllowedActions(
      $this->getMembershipField($membershipId, 'status_id:name'),
      Utils::hasReceivedPayment($membershipId)
    );
    $this->assertSame($expectedActions, array_keys($actions), "Actions offered for membership $membershipId");
  }

  /**
   * Asserts a membership's saved start and end dates.
   *
   * @param int $membershipId
   *   The membership ID.
   * @param string|null $startDate
   *   Expected start date (Y-m-d), or NULL for none.
   * @param string|null $endDate
   *   Expected end date (Y-m-d), or NULL for none.
   */
  private function assertMembershipDates(int $membershipId, ?string $startDate, ?string $endDate): void {
    $this->assertSame(
      ['start_date' => $startDate, 'end_date' => $endDate],
      [
        'start_date' => $this->getMembershipField($membershipId, 'start_date'),
        'end_date' => $this->getMembershipField($membershipId, 'end_date'),
      ],
      "Membership $membershipId dates"
    );
  }

  /**
   * Reads one field of a membership back from the database.
   *
   * @param int $membershipId
   *   The membership ID.
   * @param string $field
   *   APIv4 field name, e.g. start_date or status_id:name.
   *
   * @return mixed
   *   The saved value.
   */
  private function getMembershipField(int $membershipId, string $field) {
    return Membership::get(FALSE)
      ->addSelect($field)
      ->addWhere('id', '=', $membershipId)
      ->execute()
      ->single()[$field];
  }

}
