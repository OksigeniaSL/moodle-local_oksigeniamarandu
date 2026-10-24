@local @local_oksigeniamarandu
Feature: See the numbers that joined on WhatsApp
  In order to know who receives the notices of the site on WhatsApp
  As an administrator
  I need a list of the numbers and their consent, and to opt one out when a family asks

  Scenario: The list of numbers says how people join while nobody has
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Numbers on WhatsApp" in site administration
    Then I should see "No number has joined yet"
    And I should see "People join by writing ALTA to the number of the site"

  Scenario: A number is opted out by hand when the family asks the site, with the reason
    Given the following "users" exist:
      | username | firstname | lastname |
      | ana      | Ana       | Prueba   |
    And the number "34600000001" has joined on WhatsApp for "ana"
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Numbers on WhatsApp" in site administration
    And I should see "Active" in the "+34 600 00 00 01" "table_row"
    And I should see "Ana Prueba" in the "+34 600 00 00 01" "table_row"
    When I click on "Opt out" "link"
    Then I should see "It stops getting notices from now on."
    And I set the field "reason" to "Other"
    And I press "Opt out"
    And I should see "Write the reason."
    And I set the field "other" to "Moved to another school"
    And I press "Opt out"
    And I should see "Opted out: +34 600 00 00 01."
    And I should see "Opted out" in the "+34 600 00 00 01" "table_row"
    And "Opt out" "link" should not exist

  Scenario: The list finds a number by a part of it, or by the name of whom it stands for
    Given the following "users" exist:
      | username | firstname | lastname |
      | ana      | Ana       | Prueba   |
      | luis     | Luis      | Ejemplo  |
    And the number "34600000001" has joined on WhatsApp for "ana"
    And the number "34600000002" has joined on WhatsApp for "luis"
    And I log in as "admin"
    And I visit "/local/oksigeniamarandu/contacts.php?q=Luis"
    And I should see "Luis Ejemplo"
    And I should not see "Ana Prueba"
    When I visit "/local/oksigeniamarandu/contacts.php?q=0001"
    Then I should see "Ana Prueba"
    And I should not see "Luis Ejemplo"
    And I visit "/local/oksigeniamarandu/contacts.php?q=Nadie"
    And I should see "No number with \"Nadie\""
