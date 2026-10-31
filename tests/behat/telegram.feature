@local @local_oksigeniamarandu
Feature: The bot of the site connects Telegram as a second channel
  In order to send the notices of the site by Telegram too
  As an administrator
  I need a page that asks for the token of the bot and checks it

  Scenario: The page asks for the token of the bot and says when what was pasted is not one
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu > Notices by Telegram" in site administration
    Then I should see "Each site has its own bot."
    And I should see "Telegram keeps these messages on its servers, like the rest of your normal chats."
    And I set the field "Token of the bot" to "this is not a token"
    And I press "Connect the bot"
    And I should see "That does not look like the token of a bot"
