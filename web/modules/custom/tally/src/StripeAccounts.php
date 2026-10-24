<?php

namespace Drupal\tally;

use Drupal\user\UserDataInterface;

/**
 * Each user's own Stripe account: secret key, webhook signing secret, and the
 * random id that makes their webhook URL (/stripe/webhook/{id}).
 *
 * Both secrets are encrypted in the user's settings. Nothing here reads the
 * current user, because the webhook and the public page have none.
 */
class StripeAccounts {

  public function __construct(
    protected Settings $settings,
    protected SecretBox $box,
    protected UserDataInterface $userData,
  ) {}

  public function secretKey(int $uid): string {
    return $this->box->decrypt((string) $this->settings->forUser($uid)['stripe_secret_key']);
  }

  public function webhookSecret(int $uid): string {
    return $this->box->decrypt((string) $this->settings->forUser($uid)['stripe_webhook_secret']);
  }

  /**
   * The id in this user's webhook URL, made on first use.
   */
  public function endpointId(int $uid): string {
    $id = (string) $this->settings->forUser($uid)['stripe_endpoint'];
    if ($id === '') {
      $id = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
      $this->settings->saveFor($uid, ['stripe_endpoint' => $id]);
    }
    return $id;
  }

  /**
   * The user a webhook URL id belongs to, or NULL.
   */
  public function uidByEndpoint(string $id): ?int {
    if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $id)) {
      return NULL;
    }
    foreach ((array) $this->userData->get('tally', NULL, 'settings') as $uid => $saved) {
      if (is_array($saved) && !empty($saved['stripe_endpoint']) && hash_equals((string) $saved['stripe_endpoint'], $id)) {
        return (int) $uid;
      }
    }
    return NULL;
  }

  /**
   * Saves the secrets that are given. NULL keeps what is stored, '' clears it.
   */
  public function save(int $uid, ?string $secretKey, ?string $webhookSecret): void {
    $values = [];
    if ($secretKey !== NULL) {
      $values['stripe_secret_key'] = $secretKey === '' ? '' : $this->box->encrypt($secretKey);
    }
    if ($webhookSecret !== NULL) {
      $values['stripe_webhook_secret'] = $webhookSecret === '' ? '' : $this->box->encrypt($webhookSecret);
    }
    $this->settings->saveFor($uid, $values);
  }

  /**
   * "sk_test_…wxyz": enough to recognise a saved key, never the key.
   */
  public static function hint(string $secret): string {
    return $secret === '' ? '' : (preg_match('/^((?:sk|rk|whsec)_(?:test_|live_)?)/', $secret, $m) ? $m[1] : '') . '…' . substr($secret, -4);
  }

}
