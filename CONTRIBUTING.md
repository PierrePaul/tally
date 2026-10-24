# Contributing to Tally

Thanks for looking. Tally is a personal project, built to use Drupal as an application framework (custom entities, custom routes, a custom theme and HTMX, no CMS front end). Keep that in mind when proposing changes.

## Ground rules

- **One Drupal project, no separate frontend.** No JSON:API or decoupled setup. Live behaviour uses HTMX; keep custom JavaScript to a minimum.
- **Custom code goes in `web/modules/custom/tally` and `web/themes/custom/tally_theme`.** Never patch core or contrib in place.
- **Money is integer cents**, never floats.
- **Every user-facing string is translatable** (`t()`, `{% trans %}`), with its French added to `web/modules/custom/tally/translations/fr.po`. Then import it:
  `ddev drush locale:import fr modules/custom/tally/translations/fr.po --override=not-customized`
- **Export configuration after changing it:** `ddev drush config:export --yes`.
- **Sign-in only.** No self-registration.

## Setup and tests

Follow "Getting started" in the README, then run the tests:

```shell
ddev exec vendor/bin/phpunit -c phpunit.xml.dist --testsuite tally
```

Add or update a test for any behaviour you change.

## Pull requests

- Keep each pull request to one change, with a clear message about what and why.
- Say how you checked it (tests, and the screen you tried it on).
- Open an issue first for anything large, so we can agree on the approach before you spend time on it.

## Security

Please do not open a public issue for a security problem. Email tally@pierre-paul.com with the details and I will answer as soon as I can. The areas that matter most are the public invoice link (`/i/{token}`), the Stripe webhook and the encrypted Stripe keys.
