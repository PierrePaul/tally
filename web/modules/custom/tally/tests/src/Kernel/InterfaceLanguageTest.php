<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Core\Language\Language;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Form\SettingsForm;
use Drupal\tally\Money;

/**
 * @group tally
 */
class InterfaceLanguageTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  public function testOnlyTheOfferedLanguagesAreAccepted(): void {
    $this->assertSame('en', SettingsForm::interfaceLanguage('en'));
    $this->assertSame('fr', SettingsForm::interfaceLanguage('fr'));
    $this->assertSame('fr', SettingsForm::interfaceLanguage('de'));
    $this->assertSame('fr', SettingsForm::interfaceLanguage(NULL));
  }

  public function testMoneyFollowsTheInterfaceLanguage(): void {
    $this->assertSame('$1,234.56', Money::format(123456));
    $this->container->get('language.default')->set(new Language(['id' => 'fr']));
    $this->container->get('language_manager')->reset();
    $french = Money::format(123456);
    $this->assertStringContainsString('234,56', $french);
    $this->assertStringContainsString('$', $french);
    // A language given on purpose still wins, as invoices need.
    $this->assertSame('$1,234.56', Money::format(123456, 'CAD', 'en'));
  }

}
