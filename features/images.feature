Feature: Images are copied into Mautic
  In order to keep emails working whatever happens to the RGE Studio account
  As a marketer
  I want every image of an export copied into Mautic's media, and the email pointing only to those copies

  Background:
    Given the shared secret is "current-secret"
    And the import user is "admin"
    And the image server answers every address with a PNG image

  Scenario: An image is copied and the HTML points to the copy
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/pub/spring.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the image is saved in "media/images/rge-studio" under the name of its content hash
    And the email HTML points to the Mautic copy
    And the email HTML does not contain "media.beefree.cloud"

  Scenario: The same image used twice is copied once
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png"><img src="https://media.beefree.cloud/a.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then 1 image was downloaded

  Scenario Outline: Images are found wherever an email can reference them
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <html>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email HTML does not contain "<host>"
    And the email HTML points to the Mautic copy

    Examples:
      | html                                                                             | host                |
      | <img src="https://cdn.example.org/a.png">                                        | cdn.example.org     |
      | <img src=https://cdn.example.org/a.png>                                          | cdn.example.org     |
      | <td background="https://cdn.example.org/a.png">                                  | cdn.example.org     |
      | <video poster="https://cdn.example.org/a.png">                                   | cdn.example.org     |
      | <img srcset="https://cdn.example.org/w_600,c_scale/a.png 2x">                    | cdn.example.org     |
      | <div style="background-image:url('https://cdn.example.org/a.png')">              | cdn.example.org     |
      | <div style="background-image:url(&#x27;https://cdn.example.org/a.png&#x27;)">    | cdn.example.org     |
      | <div style="background-image:url(&quot;https://media.beefree.cloud/a.png&quot;)"> | media.beefree.cloud |
      | <a href="https://media.beefree.cloud/large.png">view</a>                         | media.beefree.cloud |
      | <img src="//cdn.example.org/a.png">                                              | cdn.example.org     |

  Scenario: An address on the designer's storage holding parentheses is copied once, whole
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <div style="background-image:url('https://media.beefree.cloud/pub/hero(1).png')">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then 1 image was downloaded
    And the answer counts 0 problems
    And the email HTML points to the Mautic copy

  Scenario: A link that starts with the address of an image keeps its own address
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://shop.example.com"><a href="https://shop.example.com/offer">Offer</a>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then 1 image was downloaded
    And the email HTML contains "href="https://shop.example.com/offer""

  Scenario: Fonts and imported stylesheets are left as designed
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <style>@import url('https://fonts.example.org/css2?family=Inter');@font-face { font-family: Inter; src: url(https://fonts.example.org/inter.woff2) }</style>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then no image was downloaded
    And the email HTML contains "https://fonts.example.org/css2?family=Inter"
    And the email HTML contains "https://fonts.example.org/inter.woff2"

  Scenario: Personalised images holding a Mautic token are left as designed
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://images.example.org/hello.png?name={contactfield=firstname}">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then no image was downloaded
    And the email HTML contains "https://images.example.org/hello.png?name={contactfield=firstname}"

  Scenario: Images already on this Mautic are left as they are
    Given a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://mautic.example.com/media/images/logo.png">
      """
    And the Mautic address is "https://mautic.example.com"
    When RGE Studio sends the design with the secret "current-secret"
    Then no image was downloaded

  Scenario Outline: An image that cannot be copied is emptied and flagged
    Given the image server answers "https://media.beefree.cloud/a.png" with <answer>
    And a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "created"
    And the email HTML contains "<img src="">"
    And the email name is "[FIX IMAGES] Spring offer"
    And the email description contains "[FIX IMAGES] https://media.beefree.cloud/a.png: <reason>"

    Examples:
      | answer                         | reason                                           |
      | status 404                     | The image server answered 404.                   |
      | an HTML page                   | The file is not a PNG, JPEG, GIF or WebP image.  |
      | an SVG image                   | The file is not a PNG, JPEG, GIF or WebP image.  |
      | an image larger than the limit | The image is larger than the limit.              |

  Scenario Outline: PNG, JPEG, GIF and WebP images are copied
    Given the image server answers "https://media.beefree.cloud/a.img" with a <format> image
    And a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.img">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the image is saved with the extension "<extension>"

    Examples:
      | format | extension |
      | PNG    | png       |
      | JPEG   | jpg       |
      | GIF    | gif       |
      | WebP   | webp      |

  Scenario: A server error is tried again
    Given the image server answers "https://media.beefree.cloud/a.png" with status 503 and then with a PNG image
    And a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email HTML points to the Mautic copy
    And 2 images were downloaded

  Scenario: A missing image is not tried again
    Given the image server answers "https://media.beefree.cloud/a.png" with status 404
    And a design 1234567 with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then 1 image was downloaded

  Scenario: Images over the per-export limit are not copied
    Given the most images copied from one export is 1
    And a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png"><img src="https://media.beefree.cloud/b.png">
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email description contains "[FIX IMAGES] https://media.beefree.cloud/b.png: Too many images in one export."

  Scenario: An address on the designer's storage that is not an image reference is flagged
    Given a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <p>See media.beefree.cloud for the original.</p>
      """
    When RGE Studio sends the design with the secret "current-secret"
    Then the email name is "[FIX IMAGES] Spring offer"
    And the email description contains "[FIX IMAGES] media.beefree.cloud: An address on the designer's storage is still in the HTML."

  Scenario: A new export tries the failed images again and clears the marker
    Given the image server answers "https://media.beefree.cloud/a.png" with status 404
    And a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png">
      """
    And RGE Studio sent the design with the secret "current-secret"
    When the image server answers "https://media.beefree.cloud/a.png" with a PNG image
    And the design is exported again with a change
    And RGE Studio sends the design with the secret "current-secret"
    Then the email name is "Spring offer"
    And the email HTML points to the Mautic copy

  Scenario: The same export sent again tries the failed images again
    Given the image server answers "https://media.beefree.cloud/a.png" with status 503
    And a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="https://media.beefree.cloud/a.png">
      """
    And RGE Studio sent the design with the secret "current-secret"
    When the image server answers "https://media.beefree.cloud/a.png" with a PNG image
    And RGE Studio sends the design with the secret "current-secret"
    Then the webhook answers 200 with the result "updated"
    And the email name is "Spring offer"
    And the email HTML points to the Mautic copy

  Scenario: Images on private network addresses are never downloaded
    Given a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="http://127.0.0.1/internal.png">
      """
    And the real HTTP client is used
    And a listener on 127.0.0.1 counts the requests it receives
    When RGE Studio sends the design with the secret "current-secret"
    Then the listener received no request
    And the email name is "[FIX IMAGES] Spring offer"

  Scenario Outline: Images on hosts the private network guard cannot check first are left as designed
    Given a design 1234567 named "Spring offer" with a working unsubscribe link and the HTML:
      """
      <img src="<address>">
      """
    And the real HTTP client is used
    And a listener on 127.0.0.1 counts the requests it receives
    When RGE Studio sends the design with the secret "current-secret"
    Then the listener received no request
    And the email HTML contains "<address>"

    Examples:
      | address                       |
      | http://localhost/internal.png  |
      | http://2130706433/internal.png |
      | http://127.1/internal.png      |
