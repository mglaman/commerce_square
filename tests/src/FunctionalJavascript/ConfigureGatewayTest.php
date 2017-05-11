<?php

namespace Drupal\Tests\commerce_square\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JSWebAssert;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\commerce\FunctionalJavascript\JavascriptTestTrait;

/**
 * Tests the creation and configuration of the gateway.
 *
 * @group commerce_square
 */
class ConfigureGatewayTest extends CommerceBrowserTestBase {

  use JavascriptTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'commerce_payment',
    'commerce_square',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_payment_gateway',
    ], parent::getAdministratorPermissions());
  }

  /**
   * Tests that a Square gateway can be configured.
   */
  public function testCreateSquareGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');

    $this->getSession()->getPage()->fillField('Name', 'Square');
    $this->getSession()->getPage()->checkField('Square');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('id', 'square');

    $this->getSession()->getPage()->fillField('configuration[app_name]', 'Drupal Commerce 2 Demo');
    $this->getSession()->getPage()->fillField('configuration[app_secret]', 'fluff');


    $this->getSession()->getPage()->fillField('configuration[test][app_id]', 'sandbox-sq0idp-nV_lBSwvmfIEF62s09z0-Q');
    $this->getSession()->getPage()->fillField('configuration[test][access_token]', 'sandbox-sq0atb-uEZtx4_Qu36ff-kBTojVNw');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->fieldExists('configuration[test][location_wrapper][location_id]');
    $this->getSession()->getPage()->selectFieldOption('configuration[test][location_wrapper][location_id]', 'CBASEHEnLmDB5kndjDx8AMlxPKAgAQ');

    $this->getSession()->getPage()->fillField('configuration[live][app_id]', 'sq0idp-nV_lBSwvmfIEF62s09z0-Q');
    $this->getSession()->getPage()->pressButton('Save');

    $is_squareup = strpos($this->getSession()->getCurrentUrl(), 'squareup.com');
    $this->assertTrue($is_squareup !== FALSE);
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\FunctionalJavascriptTests\JSWebAssert
   *   A new web-assert option for asserting the presence of elements with.
   */
  public function assertSession($name = NULL) {
    return new JSWebAssert($this->getSession($name), $this->baseUrl);
  }

  /**
   * Creates a screenshot.
   *
   * @param string $filename
   *   The file name of the resulting screenshot. If using the default phantomjs
   *   driver then this should be a JPG filename.
   * @param bool $set_background_color
   *   (optional) By default this method will set the background color to white.
   *   Set to FALSE to override this behaviour.
   *
   * @throws \Behat\Mink\Exception\UnsupportedDriverActionException
   *   When operation not supported by the driver.
   * @throws \Behat\Mink\Exception\DriverException
   *   When the operation cannot be done.
   */
  protected function createScreenshot($filename, $set_background_color = TRUE) {
    $session = $this->getSession();
    if ($set_background_color) {
      $session->executeScript("document.body.style.backgroundColor = 'white';");
    }
    $image = $session->getScreenshot();
    file_put_contents($filename, $image);
  }

}
