Feature: Reading a Custom Connector export
  In order to import only what RGE Studio really sent
  As the Mautic administrator
  I want unreadable bodies rejected and every field read the same way each time

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"

  Scenario Outline: An unreadable body is rejected
    When RGE Studio sends the body with the secret "current-secret":
      """
      <body>
      """
    Then the webhook answers 422 with the error "<error>"
    And no email exists

    Examples:
      | body                                                                            | error                                |
      | {"html":                                                                        | The body is not valid JSON.          |
      | ["a", "list"]                                                                   | The body is not a JSON object.       |
      | {"edit_url": "https://reallygoodemails.com/app/1/2/3/1234567/edit/message"}    | The export has no HTML.              |
      | {"html": "<p>Hello</p>", "edit_url": "https://reallygoodemails.com/app/edit"}   | The export has no design address.    |

  Scenario Outline: The design id is the last number in the design address
    Given a design export with the edit address "<address>"
    When RGE Studio sends the design with the secret "current-secret"
    Then one email exists for design <id>

    Examples:
      | address                                                         | id      |
      | https://reallygoodemails.com/app/1/2/3/1234567/edit/message     | 1234567 |
      | https://reallygoodemails.com/app/1/2/3/0001234567/edit/message  | 1234567 |
      | https://reallygoodemails.com/app/9/designs/42                   | 42      |

  Scenario: The subject falls back to the title
    Given a design 1234567 named "Spring offer" with a working unsubscribe link
    And the design has no subject and the title "Our spring offer"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email subject is "Our spring offer"

  Scenario: The preheader falls back to the meta description
    Given a design 1234567 named "Spring offer" with a working unsubscribe link
    And the design has no preheader and the meta description "Twenty percent off"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email description contains "Preheader: Twenty percent off"

  Scenario: An export that is not an email is ignored
    Given a design 1234567 named "Landing page" with a working unsubscribe link
    And the design template type is "page"
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with:
      """
      {"result": "ignored", "reason": "not an email"}
      """
    And no email exists

  Scenario: Every status is imported by default
    Given a design 1234567 named "Spring offer" with a working unsubscribe link
    And the design status is "draft"
    When RGE Studio sends the design with the secret "current-secret"
    Then one email exists for design 1234567
    And the email description contains "Imported from RGE Studio. Design 1234567, status draft."

  Scenario: A status not accepted is ignored when accepted statuses are set
    Given the accepted statuses are "approved"
    And a design 1234567 named "Spring offer" with a working unsubscribe link
    And the design status is "draft"
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with:
      """
      {"result": "ignored", "reason": "status not accepted"}
      """
    And no email exists
