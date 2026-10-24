<?php

namespace Drupal\Tests\tally\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tally\Settings;
use Drupal\user\UserDataInterface;

/**
 * @group tally
 */
class SettingsTest extends UnitTestCase {

  protected function settings(int $reads, array &$store): Settings {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->exactly($reads))->method('get')->willReturnCallback(function () use (&$store) {
      return $store;
    });
    $data->method('set')->willReturnCallback(function ($module, $uid, $name, $value) use (&$store) {
      $store = $value;
    });
    $account = $this->createStub(AccountProxyInterface::class);
    $account->method('id')->willReturn(5);
    return new Settings($data, $account);
  }

  public function testReadsOncePerUser(): void {
    $store = ['zoom' => '15'];
    $settings = $this->settings(1, $store);
    $settings->get('zoom');
    $settings->get('zoom');
    $this->assertSame('15', $settings->get('zoom'));
  }

  public function testSaveIsVisibleToTheNextRead(): void {
    $store = [];
    $settings = $this->settings(1, $store);
    $this->assertSame('30', $settings->get('zoom'));
    $settings->save(['zoom' => '15']);
    $this->assertSame('15', $settings->get('zoom'));
  }

}
