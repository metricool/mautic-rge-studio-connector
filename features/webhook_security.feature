Feature: Only RGE Studio can call the webhook
  In order to keep strangers from creating emails in Mautic
  As the Mautic administrator
  I want every call to carry the shared secret and stay within the size limit

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And a design 1234567 named "Spring offer" with a working unsubscribe link

  Scenario: A call with the shared secret is accepted
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200
    And one email exists for design 1234567

  Scenario: A call without the secret is rejected
    When RGE Studio sends the design without a secret
    Then the webhook answers 401 with the error "Unauthorised."
    And no email exists

  Scenario: A call with a wrong secret is rejected
    When RGE Studio sends the design with the secret "wrong-secret"
    Then the webhook answers 401 with the error "Unauthorised."
    And no email exists

  Scenario: Every call is rejected while no secret is configured
    Given no shared secret is configured
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 401 with the error "Unauthorised."
    And no email exists

  Scenario: The previous secret still works during a change of secret
    Given the shared secret is "new-secret"
    And the previous shared secret is "current-secret"
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200

  Scenario: The secret in the environment wins over the Mautic parameter
    Given the environment variable RGE_STUDIO_CONNECTOR_SECRET is "environment-secret"
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 401 with the error "Unauthorised."
    When RGE Studio sends the design with the secret "environment-secret"
    Then the webhook answers 200

  Scenario: The secret header name can be changed
    Given the secret header is "X-Connector-Key"
    When RGE Studio sends the design with the secret "current-secret" in the header "X-Connector-Key"
    Then the webhook answers 200

  Scenario: A body over the limit is rejected before it is read
    Given the body limit is 4096 bytes
    When RGE Studio sends a body of 8192 bytes with the secret "current-secret"
    Then the webhook answers 413 with the error "The export is larger than the limit."
    And no email exists

  Scenario: The webhook only answers POST
    When someone opens the webhook address with GET
    Then the webhook does not answer 200
