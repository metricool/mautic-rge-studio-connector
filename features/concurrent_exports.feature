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

  Scenario: An export that arrives after a newer one was applied changes nothing
    Given RGE Studio sent a newer export of design 1234567 with the secret "current-secret"
    When an older export of design 1234567 arrives late with the secret "current-secret"
    Then the webhook answers 200 with the result "superseded"
    And the email has the content of the newer export

  Scenario: An identical export waiting for one still running gives way to a newer export
    Given an identical export of design 1234567 is being imported
    And RGE Studio sends the design again with the secret "current-secret" and it waits
    When a newer, different export of design 1234567 is applied
    Then the waiting export answers with the result "superseded"
    And when the export it waited for finishes it answers with the result "superseded"
    And the email has the content of the newer export

  Scenario: An identical export waiting for one still running stops an older export arriving late
    Given an identical export of design 1234567 is being imported
    And RGE Studio sends the design again with the secret "current-secret" and it waits
    When an older, different export of design 1234567 arrives late with the secret "current-secret"
    Then the webhook answers 200 with the result "superseded"
    And when the export it waited for finishes the waiting export answers with the result "unchanged"
    And the email has the content of the latest export

  Scenario: An older export never completes the claim of a newer export with the same content
    Given an export of design 1234567 is being imported
    And a different export of design 1234567 took it over
    And a copy of the first export took it over again
    When the first export finishes
    Then it answers with the result "superseded"
    And the copy still applies its content when it finishes

  Scenario: A failed import releases the design at once
    Given saving the email fails once
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 500 with the error "The import failed."
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"

  Scenario: Two first exports of one design never create two emails
    When RGE Studio sends two different exports of design 1234567 at the same time
    Then one email exists for design 1234567
