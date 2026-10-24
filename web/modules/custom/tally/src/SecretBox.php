<?php

namespace Drupal\tally;

use Drupal\Core\Site\Settings as SiteSettings;

/**
 * Encrypts small secrets (a Stripe key) before they are stored, with libsodium.
 *
 * The key comes from settings.php, never from the database: $settings['tally_encryption_key']
 * when set, otherwise the site's hash salt. So a copy of the database alone
 * holds nothing usable. Changing either makes stored secrets unreadable; they
 * then read as empty and have to be entered again.
 */
class SecretBox {

  /**
   * Encrypts a secret; the result is safe to store as text.
   */
  public function encrypt(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
  }

  /**
   * The secret behind a stored value, or '' when it is empty or cannot be read.
   */
  public function decrypt(string $stored): string {
    $raw = $stored !== '' ? base64_decode($stored, TRUE) : FALSE;
    if ($raw === FALSE || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
      return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
    return $plain === FALSE ? '' : $plain;
  }

  protected function key(): string {
    $secret = (string) (SiteSettings::get('tally_encryption_key') ?: SiteSettings::getHashSalt());
    return sodium_crypto_generichash('tally-secretbox|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
  }

}
