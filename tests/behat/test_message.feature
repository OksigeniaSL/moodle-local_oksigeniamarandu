@local @local_oksigeniamarandu
Feature: Send a WhatsApp test message from the site
  In order to know that the WhatsApp account of the site is connected
  As an administrator
  I need to send a test message to my phone

  Scenario: The account is not connected yet
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Send a test message" in site administration
    Then I should see "Connect the WhatsApp account of the site first."
    And "Connect the account" "button" should exist

  Scenario: A number without the country code is refused before anything is sent
    Given the following config values are set as admin:
      | phonenumberid | 1110000000 | local_oksigeniamarandu |
      | accesstoken   | fake       | local_oksigeniamarandu |
    And I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Send a test message" in site administration
    And I set the field "Your mobile" to "600 00 00 00"
    And I press "Send"
    Then I should see "Write the number with + and the country code"
