Feature: An export becomes a Mautic email
  In order to send a design without rebuilding it in Mautic
  As a marketer
  I want every export to arrive as an unpublished email that is ready for segments and a send time

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And a design 1234567 named "Spring offer" with a working unsubscribe link
    And the design has the subject "Our spring offer"
    And the design has the preheader "Twenty percent off"
    And the design status is "in_review"

  Scenario: The first export creates an email
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the answer holds the id of the new email
    And one email exists for design 1234567

  Scenario: The email is never published by the import
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is unpublished

  Scenario: The email keeps the design's HTML in code mode
    When RGE Studio sends the design with the secret "current-secret"
    Then the email uses the code mode template
    And the email HTML is the design HTML with the images pointing to Mautic

  Scenario: The email takes the design's name and subject
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"
    And the email subject is "Our spring offer"

  Scenario: A design without a name gets a name from its id
    Given the design has no name
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "RGE Studio design 1234567"

  Scenario: Long names and subjects are cut to what Mautic stores
    Given the design name is 300 characters long
    And the design subject is 300 characters long
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is 190 characters long
    And the email subject is 190 characters long

  Scenario: The email goes into the import project
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is in the project "RGE Studio"
    And the email has no category

  Scenario: The import project is created when it does not exist yet
    Given no project is named "RGE Studio"
    When RGE Studio sends the design with the secret "current-secret"
    Then a project named "RGE Studio" exists
    And the email is in the project "RGE Studio"

  Scenario: The project name can be changed
    Given the project name is "Designs"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is in the project "Designs"

  Scenario: The email is saved as the import user
    When RGE Studio sends the design with the secret "current-secret"
    Then the email was created by "admin"

  Scenario: The import fails when the import user does not exist
    Given the import user id is 999999
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 503 with the error "The connector is not configured."
    And no email exists

  Scenario: The design's preheader stays in the HTML and Mautic's preheader field stays empty
    When RGE Studio sends the design with the secret "current-secret"
    Then the email preheader field is empty
    And the email HTML contains "Twenty percent off"
    And the email description contains "Preheader: Twenty percent off"

  Scenario: The description says where the email comes from
    When RGE Studio sends the design with the secret "current-secret"
    Then the email description starts with "Imported from RGE Studio. Design 1234567, status in_review."

  Scenario: A design without a subject is still imported and flagged
    Given the design has no subject and no title
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the email has no subject
    And the email name is "[FIX EMAIL] Spring offer"
    And the email description contains "[FIX EMAIL] The design has no subject."

  Scenario: A link Mautic rejects is flagged and the email is still created
    Given the design HTML holds the link "javascript:alert(1)"
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the email name starts with "[FIX EMAIL]"
    And the email description contains "[FIX EMAIL] Mautic rejects a link:"

  Scenario: The answer counts the problems found
    Given the design has no subject and no title
    When RGE Studio sends the design with the secret "current-secret"
    Then the answer counts 1 problem

  Scenario: The problem markers can be changed
    Given the marker for other problems is "[CHECK]"
    And the design has no subject and no title
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "[CHECK] Spring offer"
