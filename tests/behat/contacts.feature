@local @local_oksigeniamarandu
Feature: See the numbers that joined on WhatsApp
  In order to know who receives the notices of the site on WhatsApp
  As an administrator
  I need a list of the numbers and their consent

  Scenario: The list of numbers says how people join while nobody has
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Numbers on WhatsApp" in site administration
    Then I should see "No number has joined yet. People join by writing ALTA to the number of the site"

  Scenario: A number is opted out by hand when the family asks the site
    Given the following "users" exist:
      | username | firstname | lastname |
      | ana      | Ana       | Prueba   |
    And the number "34600000001" has joined on WhatsApp for "ana"
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Numbers on WhatsApp" in site administration
    And I should see "Receives notices"
    And I should see "Ana Prueba"
    When I click on "Opt out" "link"
    Then I should see "It is not told on WhatsApp."
    And I press "Continue"
    And I should see "Opted out: +34600000001."
    And I should see "Opted out" in the "+34600000001" "table_row"
    And "Opt out" "link" should not exist
