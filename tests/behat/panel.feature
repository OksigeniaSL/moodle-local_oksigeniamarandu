@local @local_oksigeniamarandu
Feature: The panel tells at a glance whether the WhatsApp notices go out
  In order to know whether the notices of the site reach the families
  As an administrator
  I need a panel with the state first and, if something is wrong, the one thing to do

  Scenario: A site without its account is sent to the wizard
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > WhatsApp notices" in site administration
    Then I should see "WhatsApp is not connected yet"
    And "Set up WhatsApp" "link" should exist

  Scenario: A site with its account running
    Given the following config values are set as admin:
      | phonenumberid | 1110000000 | local_oksigeniamarandu |
      | accesstoken   | made-up    | local_oksigeniamarandu |
    And I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > WhatsApp notices" in site administration
    Then I should see "Everything running"
    And I should see "Nothing has failed."
    And I should see "Joined and left"

  Scenario: A site whose token has expired is told how to fix it
    Given the following config values are set as admin:
      | phonenumberid | 1110000000                                       | local_oksigeniamarandu |
      | accesstoken   | made-up                                          | local_oksigeniamarandu |
      | sendblocked   | {"code":190,"message":"Fake","time":1793181600} | local_oksigeniamarandu |
    And I log in as "admin"
    When I visit "/local/oksigeniamarandu/index.php"
    Then I should see "Stopped: Meta no longer accepts the access token"
    And "Paste a new token" "link" should exist
