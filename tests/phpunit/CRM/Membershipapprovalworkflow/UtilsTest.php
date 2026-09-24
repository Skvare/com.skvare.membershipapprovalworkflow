<?php

use Civi\Api4\Contact;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\MessageTemplate;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_Membershipapprovalworkflow_UtilsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @var \CRM_Core_Transaction|null
   */
  private $tx;

  /**
   * PHPUnit 10+ dropped <listeners>, so Civi\Test\CiviTestListener never
   * runs. Replicate what its startTest() does for HeadlessInterface and
   * TransactionalInterface tests.
   */
  protected function setUp(): void {
    parent::setUp();
    $GLOBALS['CIVICRM_TEST_CASE'] = $this;
    \CRM_Core_Session::singleton()->set('userID', NULL);

    $this->setUpHeadless();

    \Civi::rebuild(['system' => TRUE])->execute();
    \Civi::reset();
    \CRM_Core_Session::singleton()->set('userID', NULL);
    $config = \CRM_Core_Config::singleton(TRUE, TRUE);
    $config->userSystem->setMySQLTimeZone();

    $this->tx = new \CRM_Core_Transaction(TRUE);
    $this->tx->rollback();
  }

  /**
   * Counterpart of CiviTestListener::endTest().
   */
  protected function tearDown(): void {
    if ($this->tx) {
      $this->tx->rollback()->commit();
      $this->tx = NULL;
    }
    \CRM_Utils_Time::resetTime();
    unset($GLOBALS['CIVICRM_TEST_CASE']);
    parent::tearDown();
  }

  public function testPrimaryMembershipIsAccepted(): void {
    CRM_Membershipapprovalworkflow_Utils::assertPrimaryMembership([
      'id' => 1,
      'owner_membership_id' => NULL,
    ]);
    $this->addToAssertionCount(1);
  }

  public function testInheritedMembershipIsRejected(): void {
    $this->expectException(CRM_Core_Exception::class);
    CRM_Membershipapprovalworkflow_Utils::assertPrimaryMembership([
      'id' => 2,
      'owner_membership_id' => 1,
    ]);
  }

  public function testLegacyStatusStateIsSeededOnlyWhenMissing(): void {
    $settings = Civi::settings();
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_NEW_STATUS_WAS_ACTIVE;
    $settings->revert($settingName);

    CRM_Membershipapprovalworkflow_Utils::seedLegacyNewStatusState();
    $this->assertTrue($settings->get($settingName));

    $settings->set($settingName, FALSE);
    CRM_Membershipapprovalworkflow_Utils::seedLegacyNewStatusState();
    $this->assertFalse($settings->get($settingName));
  }

  public function testEditableTemplateLegacyPaymentUrlIsMigrated(): void {
    $templates = MessageTemplate::get(FALSE)
      ->addSelect('id')
      ->addWhere('workflow_name', '=', 'membershipapprovalworkflow_under_review_approved')
      ->addWhere('is_reserved', '=', FALSE)
      ->execute();
    $template = $templates->first();
    $this->assertNotEmpty($template);
    $legacyUrl = 'https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}';
    MessageTemplate::update(FALSE)
      ->addWhere('id', '=', $template['id'])
      ->setValues([
        'msg_html' => '<a href="' . $legacyUrl . '">Pay now</a>',
        'msg_text' => $legacyUrl,
      ])
      ->execute();

    $upgrader = new CRM_Membershipapprovalworkflow_Upgrader();
    $this->assertTrue($upgrader->upgrade_1002());

    $updatedTemplate = MessageTemplate::get(FALSE)
      ->addSelect('msg_html', 'msg_text')
      ->addWhere('id', '=', $template['id'])
      ->execute()
      ->first();
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_html']);
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_text']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_html']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_text']);
  }

  public function testPendingMembershipAllowsReviewOrCurrent(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_PENDING
    );
    $this->assertSame(
      [CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW, CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED],
      array_keys($actions)
    );
  }

  public function testUnderReviewActionDependsOnPayment(): void {
    $unpaidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      FALSE
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED_PENDING_PAYMENT,
        CRM_Membershipapprovalworkflow_Utils::ACTION_DENIED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
      ],
      array_keys($unpaidActions)
    );

    $paidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      TRUE
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_DENIED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
      ],
      array_keys($paidActions)
    );
  }

  public function testApprovedPendingPaymentAllowsApprovedNotFulfilledOrUnderReview(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_APPROVED_PENDING_PAYMENT
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW,
      ],
      array_keys($actions)
    );
  }

  public function testCurrentMembershipAllowsSuspendedRemovedExpiredCancelledByMemberOrUnderReview(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_CURRENT
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_SUSPENDED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_REMOVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_EXPIRED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_CANCELLED_BY_MEMBER,
        CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW,
      ],
      array_keys($actions)
    );
  }

  public function testExpiredMembershipAllowsCurrent(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_EXPIRED
    );
    $this->assertSame(
      [CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED],
      array_keys($actions)
    );
  }

  public function testMembershipTypeInWorkflowDefaultsToEveryType(): void {
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES;
    Civi::settings()->revert($settingName);

    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(1));
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(999));
  }

  public function testMembershipTypeInWorkflowRespectsConfiguredList(): void {
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES;
    Civi::settings()->set($settingName, [1, 2]);

    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(1));
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow('2'));
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(3));
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(NULL));

    Civi::settings()->revert($settingName);
  }

  public function testAssertMembershipTypeInWorkflowRejectsOutOfScopeType(): void {
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES;
    Civi::settings()->set($settingName, [1]);

    CRM_Membershipapprovalworkflow_Utils::assertMembershipTypeInWorkflow(['membership_type_id' => 1]);
    $this->addToAssertionCount(1);

    $this->expectException(CRM_Core_Exception::class);
    CRM_Membershipapprovalworkflow_Utils::assertMembershipTypeInWorkflow(['membership_type_id' => 2]);
  }

  public function testCanUseStatusOverrideDependsOnProtectedStatus(): void {
    Civi::settings()->revert(CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES);
    $membershipId = $this->createTestMembership();

    $this->setMembershipStatus($membershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW);
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));

    $this->setMembershipStatus($membershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_CURRENT);
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));
  }

  public function testCanUseStatusOverrideAllowedWhenTypeOutOfScope(): void {
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES;
    $membershipId = $this->createTestMembership();
    $this->setMembershipStatus($membershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW);

    // Type is in scope by default (setting empty) - protected status blocks override.
    Civi::settings()->revert($settingName);
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));

    // Scope the workflow to a type that isn't this membership's - it falls out of scope,
    // so override is allowed even in a protected status.
    Civi::settings()->set($settingName, [0]);
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));

    Civi::settings()->revert($settingName);
  }

  /**
   * Creates a membership (with its own organization, membership type, and
   * individual contact) for canUseStatusOverride() tests to exercise
   * against a real row.
   *
   * @return int
   */
  private function createTestMembership(): int {
    $orgId = Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Test Org ' . uniqid())
      ->execute()
      ->first()['id'];

    $membershipTypeId = MembershipType::create(FALSE)
      ->addValue('name', 'Test Type ' . uniqid())
      ->addValue('member_of_contact_id', $orgId)
      ->addValue('financial_type_id:name', 'Member Dues')
      ->addValue('period_type', 'rolling')
      ->addValue('duration_unit', 'year')
      ->execute()
      ->first()['id'];

    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Member ' . uniqid())
      ->execute()
      ->first()['id'];

    return Membership::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('membership_type_id', $membershipTypeId)
      ->execute()
      ->first()['id'];
  }

  /**
   * Sets a membership's status_id directly via a raw DAO write - bypassing
   * this extension's own hooks, which is exactly what's needed here: these
   * tests exercise canUseStatusOverride()'s read-only logic against an
   * arbitrary status, not the write-guards that are tested elsewhere.
   */
  private function setMembershipStatus(int $membershipId, string $statusName): void {
    CRM_Core_DAO::setFieldValue(
      'CRM_Member_DAO_Membership',
      $membershipId,
      'status_id',
      CRM_Membershipapprovalworkflow_Utils::getStatusIdByName($statusName)
    );
  }

}
