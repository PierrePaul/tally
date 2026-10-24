<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group tally
 */
class LoginErrorMessageTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    \Drupal::moduleHandler()->loadInclude('tally', 'module');
  }

  protected function run_(string $name, string $core_message, ?Response $response = NULL): array {
    $form = ['name' => ['#parents' => ['name'], '#name' => 'name', '#array_parents' => ['name']]];
    $state = new FormState();
    $state->setValue('name', $name);
    $state->setErrorByName('name', $core_message);
    if ($response) {
      $state->setResponse($response);
    }
    tally_login_error_message($form, $state);
    return array_map('strval', $state->getErrors());
  }

  public function testWrongPasswordGetsTheDesignCopy(): void {
    $errors = $this->run_('someone', 'Unrecognized username or password.');
    $this->assertStringContainsString('don’t match', $errors['name']);
  }

  public function testBlockedAccountMessageIsKept(): void {
    User::create(['name' => 'locked', 'status' => 0])->save();
    $errors = $this->run_('locked', 'The username locked has not been activated or is blocked.');
    $this->assertSame('The username locked has not been activated or is blocked.', $errors['name']);
  }

  public function testFloodResponseIsLeftAlone(): void {
    $errors = $this->run_('someone', 'core message', new Response('', 403));
    $this->assertSame('core message', $errors['name']);
  }

}
