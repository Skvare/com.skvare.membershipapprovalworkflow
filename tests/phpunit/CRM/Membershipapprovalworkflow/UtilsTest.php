<?php

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
    $templates = civicrm_api3('MessageTemplate', 'get', [
      'workflow_name' => 'membershipapprovalworkflow_under_review_approved',
      'is_reserved' => 0,
      'return' => ['id'],
    ])['values'];
    $template = reset($templates);
    $this->assertNotFalse($template);
    $legacyUrl = 'https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}';
    civicrm_api3('MessageTemplate', 'create', [
      'id' => $template['id'],
      'msg_html' => '<a href="' . $legacyUrl . '">Pay now</a>',
      'msg_text' => $legacyUrl,
    ]);

    $upgrader = new CRM_Membershipapprovalworkflow_Upgrader();
    $this->assertTrue($upgrader->upgrade_1002());

    $updatedTemplate = civicrm_api3('MessageTemplate', 'getsingle', [
      'id' => $template['id'],
      'return' => ['msg_html', 'msg_text'],
    ]);
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_html']);
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_text']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_html']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_text']);
  }

  public function testPendingMembershipOnlyAllowsReview(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_PENDING
    );
    $this->assertSame([CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW], array_keys($actions));
  }

  public function testUnderReviewActionDependsOnPayment(): void {
    $unpaidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      FALSE
    );
    $this->assertSame([CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED_PENDING_PAYMENT], array_keys($unpaidActions));

    $paidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      TRUE
    );
    $this->assertSame([CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED], array_keys($paidActions));
  }

}
