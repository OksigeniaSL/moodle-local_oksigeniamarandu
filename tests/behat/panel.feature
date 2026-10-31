@local @local_oksigeniamarandu
Feature: The panel tells at a glance whether the notices go out, by WhatsApp and by Telegram
  In order to know whether the notices of the site reach the families
  As an administrator
  I need a panel with the state first and, if something is wrong, the one thing to do

  Scenario: A site without any channel starts by connecting one
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu > Notices panel" in site administration
    Then I should see "Start by connecting a channel"
    And "Connect Telegram" "link" should exist
    And "Connect WhatsApp" "link" should exist
    And I should see "What you will see here"

  Scenario: A site with WhatsApp and no notice switched on is sent to choose them
    Given the following config values are set as admin:
      | phonenumberid | 1110000000      | local_oksigeniamarandu |
      | accesstoken   | made-up         | local_oksigeniamarandu |
      | displayphone  | +34 600 000 000 | local_oksigeniamarandu |
    And I log in as "admin"
    When I visit "/local/oksigeniamarandu/index.php"
    Then I should see "The notices that go out are still to choose"
    And I click on "Choose which notices go out" "link" in the "region-main" "region"
    And I should see "Which notices go out"
    And I should see "General notice"
    And I should see "text still has to go to Meta"

  Scenario: A site ready to notify waits for the first one to join
    Given the following config values are set as admin:
      | phonenumberid   | 1110000000                          | local_oksigeniamarandu |
      | accesstoken     | made-up                             | local_oksigeniamarandu |
      | displayphone    | +34 600 000 000                     | local_oksigeniamarandu |
      | enablednotices  | local_oksigeniamarandu/notification | local_oksigeniamarandu |
    And I log in as "admin"
    When I visit "/local/oksigeniamarandu/index.php"
    Then I should see "Ready to notify: somebody has to join"
    And I should see "1 notice switched on"

  Scenario: A site whose token has expired is told how to fix it
    Given the following config values are set as admin:
      | phonenumberid  | 1110000000                                       | local_oksigeniamarandu |
      | accesstoken    | made-up                                          | local_oksigeniamarandu |
      | displayphone   | +34 600 000 000                                  | local_oksigeniamarandu |
      | enablednotices | local_oksigeniamarandu/notification              | local_oksigeniamarandu |
      | sendblocked    | {"code":190,"message":"Fake","time":1793181600} | local_oksigeniamarandu |
    And I log in as "admin"
    When I visit "/local/oksigeniamarandu/index.php"
    Then I should see "Stopped: Meta no longer accepts the access token"
    And "Paste a new token" "link" should exist
