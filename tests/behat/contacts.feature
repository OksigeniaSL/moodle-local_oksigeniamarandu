@local @local_oksigeniamarandu
Feature: See the numbers that joined on WhatsApp
  In order to know who receives the notices of the site on WhatsApp
  As an administrator
  I need a list of the numbers and their consent

  Scenario: The list of numbers says how people join while nobody has
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Numbers on WhatsApp" in site administration
    Then I should see "No number has joined yet. People join by writing ALTA to the number of the site"
