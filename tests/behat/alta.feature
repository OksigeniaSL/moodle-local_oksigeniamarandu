@local @local_oksigeniamarandu
Feature: A family joins the notices on WhatsApp from its private link
  In order to get the notices of the site on WhatsApp
  As a family without a Moodle account
  I need a page that opens WhatsApp with the message written, or shows it as a QR code

  Scenario: The private link names whom it stands for and shows the QR code on a computer
    Given the following "users" exist:
      | username | firstname | lastname |
      | ana      | Ana       | Prueba   |
    And the following config values are set as admin:
      | displayphone | +34 900 000 000 | local_oksigeniamarandu |
    When I open the WhatsApp sign-up page of "ana"
    Then I should see "For Ana Prueba"
    And I should see "Scan it with your phone"
    And I should see "Or open WhatsApp on this computer"

  Scenario: A link with a code that does not exist says so
    When I open the WhatsApp sign-up page with a code that does not exist
    Then I should see "This link does not work"
