<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Form\SettingsForm;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class SettingsLogoTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'tally'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  protected function file(User $owner): File {
    $file = File::create(['uid' => $owner->id(), 'uri' => 'public://logo-' . $owner->id() . '.png', 'status' => 1]);
    $file->save();
    return $file;
  }

  protected function saveLogo(User $as, int $new, int $old): int {
    $this->container->get('current_user')->setAccount($as);
    $form = SettingsForm::create($this->container);
    $method = new \ReflectionMethod($form, 'saveLogo');
    return $method->invoke($form, $new, $old);
  }

  public function testAnotherUsersFileIsNotAdopted(): void {
    $a = User::create(['name' => 'a']);
    $a->save();
    $b = User::create(['name' => 'b']);
    $b->save();
    $theirs = $this->file($b);
    $this->assertSame(0, $this->saveLogo($a, (int) $theirs->id(), 0));
    $this->assertNotNull(File::load($theirs->id()));
  }

  public function testAnotherUsersOldFileIsNotDeleted(): void {
    $a = User::create(['name' => 'a']);
    $a->save();
    $b = User::create(['name' => 'b']);
    $b->save();
    $theirs = $this->file($b);
    $mine = $this->file($a);
    $this->saveLogo($a, (int) $mine->id(), (int) $theirs->id());
    $this->assertNotNull(File::load($theirs->id()));
  }

  public function testOwnLogoIsReplaced(): void {
    $a = User::create(['name' => 'a']);
    $a->save();
    $old = $this->file($a);
    $new = $this->file($a);
    $this->assertSame((int) $new->id(), $this->saveLogo($a, (int) $new->id(), (int) $old->id()));
    $this->assertNull(File::load($old->id()));
  }

}
