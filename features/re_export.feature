Feature: Exporting the same design again
  In order to fix a design in RGE Studio and export it again
  As a designer
  I want a new export to update the same email while nothing has used it, and never touch an email in use

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And a design 1234567 named "Spring offer" with a working unsubscribe link
    And RGE Studio sent the design with the secret "current-secret"

  Scenario: The same export again changes nothing
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "unchanged"
    And the answer holds the id of the first email
    And one email exists for design 1234567

  Scenario: A changed export updates the same email
    When the design subject changes to "New subject"
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "updated"
    And the answer holds the id of the first email
    And the email subject is "New subject"
    And one email exists for design 1234567

  Scenario: An updated email stays unpublished and gets a new version
    When the design subject changes to "New subject"
    And RGE Studio sends the design with the secret "current-secret"
    Then the email is unpublished
    And the email version went up by 1

  Scenario: An update clears Mautic's preheader field again
    Given someone filled the email's preheader field in Mautic
    When the design subject changes to "New subject"
    And RGE Studio sends the design with the secret "current-secret"
    Then the email preheader field is empty

  Scenario Outline: An email in use is never changed and a new email is created
    Given <use>
    When the design subject changes to "New subject"
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the answer holds the id of a second email
    And the first email still has the subject "Our spring offer"

    Examples:
      | use                                                   |
      | the first email was published                         |
      | the first email was published with a send time tomorrow |
      | the first email was sent                              |
      | the first email was sent as an A/B test               |
      | the first email has a send record                     |
      | the first email has a queued message                  |
      | the first email is the parent of an A/B test          |
      | the first email is a variant in an A/B test           |
      | the first email has a translation                     |
      | the first email is a translation                      |

  Scenario: The newest email of a design is the one updated next
    Given the first email was published
    And the design subject changes to "Second version"
    And RGE Studio sent the design with the secret "current-secret"
    When the design subject changes to "Third version"
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "updated"
    And the second email has the subject "Third version"

  Scenario: An email deleted in Mautic is created again by the next export
    Given the first email was deleted in Mautic
    When the design subject changes to "New subject"
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And one email exists for design 1234567

  Scenario: An email deleted in Mautic is created again even by an identical export
    Given the first email was deleted in Mautic
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
