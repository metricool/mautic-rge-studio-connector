Feature: Exports of one design that arrive at the same time
  In order to never get two emails, or an older version, from exports that overlap
  As a marketer
  I want only the latest export of a design to be applied, and an honest answer when it cannot be

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And a design 1234567 named "Spring offer" with a working unsubscribe link

  Scenario: An identical export waits for the one still running and then reports unchanged
    Given an identical export of design 1234567 is being imported and finishes in 2 seconds
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "unchanged"
    And one email exists for design 1234567

  Scenario: An identical export still running after the wait is answered as busy
    Given the wait for a running export is 1 second
    And an identical export of design 1234567 is being imported and does not finish
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 503 with the error "An identical export of this design is still being imported."
    And no email exists

  Scenario: An export whose run stopped half way does not block the design for long
    Given an identical export of design 1234567 started 2 minutes ago and never finished
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"

  Scenario: A newer export takes over from an older one still running
    Given an older export of design 1234567 is being imported
    When RGE Studio sends a changed version of the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And when the older export finishes it answers with the result "superseded"
    And the email has the content of the newer export

  Scenario: The latest applied export stops an older one still running
    Given RGE Studio sent the design with the secret "current-secret"
    And an older, different export of design 1234567 is being imported
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "unchanged"
    And when the older export finishes it answers with the result "superseded"
    And the email has the content of the latest export

  Scenario: A failed import releases the design at once
    Given saving the email fails once
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 500 with the error "The import failed."
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"

  Scenario: Two first exports of one design never create two emails
    When RGE Studio sends two different exports of design 1234567 at the same time
    Then one email exists for design 1234567
