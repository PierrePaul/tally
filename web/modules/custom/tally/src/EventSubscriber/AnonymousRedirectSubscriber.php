<?php

namespace Drupal\tally\EventSubscriber;

use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Htmx\HtmxRequestInfoTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends anonymous visitors to the sign-in screen instead of a 403 page.
 */
class AnonymousRedirectSubscriber implements EventSubscriberInterface {

  use HtmxRequestInfoTrait;

  /**
   * The request being answered, for the htmx helpers.
   */
  protected Request $request;

  public function __construct(protected AccountInterface $currentUser) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before the default 403 page handling.
    return [KernelEvents::EXCEPTION => ['onException', 75]];
  }

  /**
   * Redirects a denied anonymous HTML or htmx request to sign-in.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest()
      || !$this->currentUser->isAnonymous()
      || !$event->getThrowable() instanceof AccessDeniedHttpException
      || $request->getRequestFormat() !== 'html') {
      return;
    }
    $this->request = $request;
    $htmx = $this->isHtmxRequest();
    $destination = $request->getPathInfo();
    if ($htmx) {
      // A fragment request (poll, start, stop): come back to the page the
      // visitor was on, not to the fragment's POST-only address. Without that
      // header, the front page is the app.
      $parts = parse_url($this->getHtmxCurrentUrl());
      $destination = (($parts['path'] ?? '') ?: '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $url = Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]]);
    if (!$htmx) {
      $event->setResponse(new RedirectResponse($url->toString()));
      return;
    }
    // htmx follows a 302 silently and swaps the login page into the target, and
    // hx-select then finds nothing, so nothing happens. HX-Redirect makes it
    // navigate the whole window instead.
    $headers = (new Htmx())->redirectHeader($url)->getHeaders();
    $event->allowCustomResponseCode();
    $event->setResponse(new Response('', 200, $headers->all()));
  }

  protected function getRequest(): Request {
    return $this->request;
  }

}
