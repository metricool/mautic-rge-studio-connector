Feature: The design's tags choose the email type
  In order to get the right kind of email without editing it in Mautic
  As a designer
  I want a design tag to say whether the email is for a campaign or for a segment

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And a design 1234567 named "Spring offer" with a working unsubscribe link

  Scenario: A design without a type tag becomes a segment email
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a segment email

  Scenario: The tag "mautic-segment" makes a segment email
    Given the design has the tags "mautic-segment"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a segment email

  Scenario: The tag "mautic-campaign" makes a campaign email
    Given the design has the tags "mautic-campaign"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a campaign email

  Scenario: Tags are read without regard to case or spaces
    Given the design has the tags " Mautic-Campaign "
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a campaign email

  Scenario: A design with both type tags becomes a segment email and is flagged
    Given the design has the tags "mautic-campaign, mautic-segment"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a segment email
    And the email name is "[FIX EMAIL] Spring offer"
    And the email description contains "[FIX EMAIL] The design has both type tags (mautic-campaign and mautic-segment), so it was saved as a segment email."

  Scenario: The type tags can be changed
    Given the campaign tag is "for-campaign"
    And the design has the tags "for-campaign"
    When RGE Studio sends the design with the secret "current-secret"
    Then the email is a campaign email

  Scenario: A new export can change the type of an email that may still change
    Given the design has the tags "mautic-campaign"
    And RGE Studio sent the design with the secret "current-secret"
    When the design tags change to "mautic-segment"
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "updated"
    And the email is a segment email

  Scenario: A campaign email that an action sends stays a campaign email
    Given the design has the tags "mautic-campaign"
    And RGE Studio sent the design with the secret "current-secret"
    And a campaign step sends the email
    When the design tags change to "mautic-segment"
    And RGE Studio sends the design with the secret "current-secret"
    Then the email is a campaign email
    And the email description contains "[FIX EMAIL] It stays a campaign email, because a campaign, form or point action sends it."

  Scenario: Turning a segment email into a campaign email clears its send settings
    Given RGE Studio sent the design with the secret "current-secret"
    And the email has a send window and excluded segments
    When the design tags change to "mautic-campaign"
    And RGE Studio sends the design with the secret "current-secret"
    Then the email is a campaign email
    And the email has no send window and no excluded segments
