<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @group tally
 */
class AnonymousRedirectSubscriberTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  protected function denied(Request $request): ExceptionEvent {
    $event = new ExceptionEvent($this->container->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException());
    $this->container->get('tally.anonymous_redirect')->onException($event);
    return $event;
  }

  public function testPlainRequestIsRedirected(): void {
    $response = $this->denied(Request::create('/time'))->getResponse();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/user/login?destination=/time', $response->getTargetUrl());
  }

  public function testHtmxRequestComesBackToThePageItWasOn(): void {
    $request = Request::create('/time/timer/stop', 'POST');
    $request->headers->set('HX-Request', 'true');
    $request->headers->set('HX-Current-URL', 'https://tally.ddev.site/time?view=list');
    $response = $this->denied($request)->getResponse();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNotInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/user/login?destination=/time%3Fview%3Dlist', $response->headers->get('HX-Redirect'));
  }

  public function testHtmxRequestWithoutCurrentUrlFallsBackToTheFrontPage(): void {
    $request = Request::create('/time/timer/stop', 'POST');
    $request->headers->set('HX-Request', 'true');
    $response = $this->denied($request)->getResponse();
    $this->assertSame('/user/login?destination=/', $response->headers->get('HX-Redirect'));
  }

}
