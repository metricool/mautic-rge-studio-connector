Feature: The Custom Connector's connection test
  In order to see a success message when the connector is set up in RGE Studio
  As a designer
  I want the empty call RGE Studio makes on "Connect" to answer 200 when Mautic is ready

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"

  Scenario: An empty body is the connection test
    When RGE Studio tests the connection with the secret "current-secret"
    Then the webhook answers 200 with:
      """
      {"ok": true}
      """
    And no email exists

  Scenario: A body of only white space is also the connection test
    When RGE Studio sends the body with the secret "current-secret":
      """


      """
    Then the webhook answers 200
    And no email exists

  Scenario: The connection test fails when no import user is set
    Given no import user is set
    When RGE Studio tests the connection with the secret "current-secret"
    Then the webhook answers 503 with the error "The connector is not configured."

  Scenario: The connection test fails when the project name is empty
    Given the project name is ""
    When RGE Studio tests the connection with the secret "current-secret"
    Then the webhook answers 503 with the error "The connector is not configured."

  Scenario: The connection test needs the secret too
    When RGE Studio tests the connection without a secret
    Then the webhook answers 401 with the error "Unauthorised."
