Feature: The unsubscribe link is checked
  In order to never send an email that contacts cannot leave
  As a marketer
  I want every import to check the unsubscribe link the way Mautic needs it, and flag what is wrong

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And Mautic's unsubscribe text setting holds the unsubscribe address

  Scenario: The unsubscribe address token as a whole link address works
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <a href="{unsubscribe_url}">Unsubscribe</a>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"
    And the answer counts 0 problems

  Scenario: The unsubscribe text token as text works
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <p>{unsubscribe_text}</p>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"

  Scenario: A scheme in front of the token is removed, the way Mautic does at send time
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <a href="https://{unsubscribe_url}">Unsubscribe</a>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email HTML contains "href="{unsubscribe_url}""
    And the email name is "Spring offer"

  Scenario Outline: A wrong unsubscribe link is flagged and the email is still created
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <html>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the email is unpublished
    And the email name is "[FIX UNSUBSCRIBE] Spring offer"
    And the email description contains "[FIX UNSUBSCRIBE] <problem>"

    Examples:
      | html                                                                                        | problem                                                                                                                        |
      | <p>No link here</p>                                                                         | There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.         |
      | <!-- <a href="{unsubscribe_url}">Unsubscribe</a> -->                                        | The unsubscribe link is only inside HTML comments, so most email apps never show it.                                           |
      | <a href="{unsubscribe_url}">Unsubscribe</a><a href="https://x.example/?u={unsubscribe_url}">Again</a> | An unsubscribe token sits inside a longer address or inside an attribute, so that link breaks.                    |
      | <a href="{unsubscribe_url}">Unsubscribe</a><img alt="{unsubscribe_text}">                    | An unsubscribe token sits inside a longer address or inside an attribute, so that link breaks.                                 |
      | <a href="{unsubscribe_url}">Unsubscribe</a><a href="%7Bunsubscribe_url%7D">Again</a>         | An unsubscribe token is URL-encoded (%7B...%7D), and Mautic never replaces it.                                                  |
      | <a href="{unsubscribe_url}">Unsubscribe</a><p>&#123;unsubscribe_text&#125;</p>               | An unsubscribe token is written with HTML entities, and Mautic never replaces it.                                               |
      | <a href="{unsubscribe_url}">Unsubscribe</a><a href="https://mautic.example.com/email/unsubscribe/abc">Old</a> | A pasted unsubscribe address (/email/unsubscribe/) is in the HTML. It only works for one contact.                |
      | <head><link rel="preload" href="{unsubscribe_url}"></head><p>No link here</p>                | An unsubscribe token sits inside a longer address or inside an attribute, so that link breaks.                                 |
      | <head><title>{unsubscribe_text}</title></head><p>No link here</p>                            | There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.         |
      | <style>/* {unsubscribe_text} */</style><p>No link here</p>                                   | There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.         |

  Scenario: The unsubscribe address token as the whole address of an image map area works
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <map name="footer"><area shape="rect" coords="0,0,99,20" href="{unsubscribe_url}" alt="Unsubscribe"></map>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"
    And the answer counts 0 problems

  @header-rule
  Scenario: Mautic would send no List-Unsubscribe header
    Given Mautic's unsubscribe text setting does not hold the unsubscribe address
    And a design 1234567 named "Spring offer" with the HTML:
      """
      <p>{unsubscribe_text}</p>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "[FIX UNSUBSCRIBE] Spring offer"
    And the email description contains "[FIX UNSUBSCRIBE] Mautic will send no List-Unsubscribe header."

  @header-rule
  Scenario: A token in capitals gets no List-Unsubscribe header
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <a href="{unsubscribe_url}">Unsubscribe</a><p>{UNSUBSCRIBE_TEXT}</p>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "[FIX UNSUBSCRIBE] Spring offer"
    And the email description contains "[FIX UNSUBSCRIBE] An unsubscribe token is not all in lower case, so it gets no List-Unsubscribe header and no tracking."

  @no-header-rule
  Scenario: Where Mautic adds the List-Unsubscribe header to every email, the header is not checked
    Given Mautic's unsubscribe text setting does not hold the unsubscribe address
    And a design 1234567 named "Spring offer" with the HTML:
      """
      <p>{unsubscribe_text}</p><p>{UNSUBSCRIBE_TEXT}</p>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"
    And the answer counts 0 problems

  Scenario: A quoted greater-than sign inside an attribute does not hide the link
    Given a design 1234567 named "Spring offer" with the HTML:
      """
      <a title="a > b" href="{unsubscribe_url}">Unsubscribe</a>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"

  Scenario: The pasted address paths can be changed
    Given the pasted unsubscribe paths are "/leave/"
    And a design 1234567 named "Spring offer" with the HTML:
      """
      <a href="{unsubscribe_url}">Unsubscribe</a><a href="https://mautic.example.com/leave/abc">Old</a>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email description contains "[FIX UNSUBSCRIBE] A pasted unsubscribe address (/leave/) is in the HTML. It only works for one contact."
