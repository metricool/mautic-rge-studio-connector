# RGE Studio Connector for Mautic

[![CI](https://github.com/metricool/mautic-rge-studio-connector/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/metricool/mautic-rge-studio-connector/actions/workflows/ci.yml) [![Coverage](https://codecov.io/gh/metricool/mautic-rge-studio-connector/graph/badge.svg)](https://app.codecov.io/gh/metricool/mautic-rge-studio-connector)

A Mautic plugin that turns RGE Studio (formerly Beefree) Custom Connector exports into Mautic emails.

A designer exports a design from RGE Studio through its Custom Connector. Mautic receives the export at `/rge-studio/webhook` and creates the email by itself, ready for Marketing to pick segments and a send time.

## What it does

- Every export becomes an **unpublished** email in code mode, so nothing is ever sent by the import alone.
- On Mautic 7.2 every email goes into one Mautic project (default "RGE Studio"), and an update puts it back if it was taken out. Mautic 5.2 has no projects. The plugin sets no category.
- The design tag `mautic-campaign` makes a campaign email. Any other export becomes a segment email. A design with both type tags becomes a segment email and is flagged.
- **Every image is copied into Mautic's media**, in `media/images/rge-studio/`, named after its content, and the saved HTML points to these copies. An image that cannot be copied has its address emptied. Any address on RGE Studio's image storage still left in the HTML is listed in the description under the images marker. Images whose address holds a Mautic token, such as personalised images, are left as designed. PNG, JPEG, GIF and WebP are copied. SVG is not, because email apps do not show it and it can carry script. Images on private network addresses are never downloaded, and neither are images on hosts that are not an IP address or a full name, such as `localhost`, which stay as designed.
- RGE Studio's own preheader stays in the HTML as designed. Mautic's preheader field stays empty, and the preheader text is shown in the email's description.
- The plain text version is emptied on every import, so Mautic builds it from the new HTML and an update never sends old text.
- The unsubscribe link is checked: `{unsubscribe_url}` as the whole address of a link (`a` or `area`), or `{unsubscribe_text}` as text in the body. Glued, encoded, commented out or pasted links are reported, and so is the token in the address of any other element. Text in the head, title, style or script does not count as a link.
- The List-Unsubscribe header is checked on Mautic 7.2, which adds it only when the HTML holds `{unsubscribe_url}` in lower case, or `{unsubscribe_text}` with `|URL|` in Mautic's unsubscribe text setting. Mautic 5.2 adds it to every email that is not transactional, so there is nothing to check.
- Every link must be a whole Mautic token, an anchor, a valid http, https, ftp or ftps address, or a mailto, tel or sms link. This is the rule Mautic 7.2 applies when an email is saved in its editor. Any other link is reported, on Mautic 5.2 too.
- **Problems never drop an export.** The email is still created, unpublished, with a marker at the start of its name (`[FIX UNSUBSCRIBE]`, `[FIX IMAGES]` or `[FIX EMAIL]`) and each problem in its description.
- A new export of the same design updates the same email only while it is unpublished, has never been sent or queued, and is not part of an A/B test or a translation. Otherwise a new email is created. An email that Mautic considers locked, because someone has it open in the editor, also gets a new email, flagged in its name, since their next save would bring the old HTML back. Mautic's lock ends when they save, cancel or leave through a Mautic link, or after `max_entity_lock_time` when that is set. An export identical to the last one changes nothing, unless some of its images were not copied: then it is applied again and those images are tried again. Exports of one design are applied in the order their requests arrived, so an export that arrived before one already received changes nothing either.
- Every email is saved as one Mautic user chosen in the settings, so the email list shows where imports come from.

## Answers

| Answer | When |
|---|---|
| 200 | Every readable export once the plugin is set up, the connection test (an empty body) and exports that are ignored (not an email, or a status not accepted) |
| 401 | The shared secret header is missing or wrong, or no secret is configured |
| 413 | The body is larger than the limit, or PHP did not hand it over (over `post_max_size`). A too large declared length is answered before the secret is checked |
| 422 | The body is not a readable export |
| 500 | The import failed for an unexpected reason, which is logged |
| 503 | The plugin is not configured (no import user, no project name on Mautic 7.2, or its table not created yet), or an identical export of the same design is still being imported |

RGE Studio shows a success message only for 200 and does not retry, so the plugin answers 200 for every readable export and flags problems in Mautic. An export answered 413, 500 or 503 is not imported and has to be exported again.

## Requirements

- Mautic 5.2, or Mautic 7.2 or later. PHP 8.2, 8.3 or 8.4.
- PHP's `post_max_size` above `rge_studio_connector_max_body_bytes`, or larger exports are answered 413.
- Symfony DomCrawler, CssSelector and HttpClient, which installing the plugin with Composer adds. Mautic 5.2 ships DomCrawler only with its development packages.
- The plugin's table, which Mautic creates when it reloads its plugins after the plugin is added. Until then the connection test answers 503.
- A Mautic user for the imports. An inactive user with the fewest permissions is enough, since nobody logs in with it.
- Marketing users need permission to view, edit, publish and delete other users' emails, or they will not see the imports in the email list.
- Every web server of the Mautic install must share the `media/images` folder.
- The webhook address must be reachable from RGE Studio's servers, with no bot challenge in front of it.

## Settings

The shared secret is read from the environment variable `RGE_STUDIO_CONNECTOR_SECRET`. During a change of secret, `RGE_STUDIO_CONNECTOR_PREVIOUS_SECRET` holds the old one, and both are accepted. Without the environment variables, the parameters below are used. The same secret goes into a custom header of the Custom Connector in RGE Studio.

Every other setting is a Mautic parameter, set in `config/local.php`.

| Parameter | Default | Meaning |
|---|---|---|
| `rge_studio_connector_secret` | empty | Shared secret, when not set in the environment |
| `rge_studio_connector_previous_secret` | empty | Previous secret during a change |
| `rge_studio_connector_secret_header` | `X-Webhook-Secret` | Header that carries the secret |
| `rge_studio_connector_user_id` | `0` | Id of the Mautic user every import is saved as (required) |
| `rge_studio_connector_project` | `RGE Studio` | Project every import goes into (Mautic 7.2) |
| `rge_studio_connector_campaign_tag` | `mautic-campaign` | Design tag for a campaign email |
| `rge_studio_connector_segment_tag` | `mautic-segment` | Design tag for a segment email |
| `rge_studio_connector_accepted_statuses` | empty (all) | Design statuses imported |
| `rge_studio_connector_marker_unsubscribe` | `[FIX UNSUBSCRIBE]` | Name marker for an unsubscribe problem |
| `rge_studio_connector_marker_images` | `[FIX IMAGES]` | Name marker for an image not copied |
| `rge_studio_connector_marker_other` | `[FIX EMAIL]` | Name marker for any other problem |
| `rge_studio_connector_image_folder` | `rge-studio` | Folder under `media/images` for copied images |
| `rge_studio_connector_designer_hosts` | `media.beefree.cloud`, `imgdist.com` | Image hosts whose addresses are copied wherever they appear, and flagged if one stays |
| `rge_studio_connector_pasted_unsubscribe_paths` | `/email/unsubscribe/`, `/email/dnc/` | Paths that show a pasted unsubscribe address |
| `rge_studio_connector_max_body_bytes` | 10 MB | Largest export accepted |
| `rge_studio_connector_max_image_bytes` | 5 MB | Largest image copied |
| `rge_studio_connector_max_images` | `150` | Most images copied from one export |
| `rge_studio_connector_image_seconds` | `25` | Seconds after the request arrived within which all image copies of one export must finish. When the wait for an identical export already used that time, the copies still get 10 seconds |
| `rge_studio_connector_image_attempts` | `2` | Download attempts per image |
| `rge_studio_connector_claim_seconds` | `60` | Seconds after which an unfinished import of a design no longer holds back an identical export |
| `rge_studio_connector_wait_seconds` | `35` | Seconds after the request arrived that an export waits for an identical one still being imported |

## Code checks

The code passes PHPStan at its highest level (`phpstan.neon`) and Mautic's PHP-CS-Fixer rules (`.php-cs-fixer.php`), with one difference: full docblocks stay, `@return` included. Every function stays under cyclomatic complexity 10 (`phpcs.xml.dist`, PHP_CodeSniffer). The tests (`phpunit.xml.dist`) are measured with mutation testing (`infection.json5`). The behaviour is written as Gherkin features in `features/`.

The checks and the tests run from a Mautic install with its development dependencies, with the plugin in `plugins/RgeStudioConnectorBundle`. The tests run on Mautic 7.2 with `phpunit.xml.dist` and on Mautic 5.2 with `phpunit-mautic5.xml.dist` (PHPUnit 9). The other checks run on Mautic 7.2. PHP_CodeSniffer and Infection are not part of Mautic's development dependencies.

GitHub Actions (`.github/workflows/ci.yml`) run the tests on Mautic 5.2.4 with PHP 8.2 and 8.3, on Mautic 7.2.0 with PHP 8.3 and on the latest 7.2 release with PHP 8.2, 8.3 and 8.4, and the other checks with mutation testing on the latest 7.2 release, for every pull request. The test coverage of every run goes to [Codecov](https://app.codecov.io/gh/metricool/mautic-rge-studio-connector).

Scenarios tagged `@projects`, `@header-rule` or `@email-version` need Mautic 7.2. Scenarios tagged `@no-projects` or `@no-header-rule` describe Mautic 5.2.

## Licence

GPL-3.0-or-later, the same as Mautic.
