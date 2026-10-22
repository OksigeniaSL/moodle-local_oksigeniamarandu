@local @local_oksigeniamarandu
Feature: Set up the WhatsApp of the site step by step
  In order to send notices from the official WhatsApp account of the site
  As an administrator
  I need a wizard that connects the account, lets Meta reach the site and sends a test message

  Scenario: The first step asks for the details of the app in Meta
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Set up WhatsApp" in site administration
    Then I should see "Connect the WhatsApp of the site"
    And I should see "Step 1 of 2 · Account"
    And "Check the connection" "button" should exist

  Scenario: Details that are not right are refused before asking Meta
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Set up WhatsApp" in site administration
    When I set the field "mrd-phonenumberid" to "not a number"
    And I press "Check the connection"
    Then I should see "Only digits: a long number, without spaces."
    And I should see "Paste the access token."

  Scenario: Once connected, the second part gives the details for Meta and waits for Meta to call
    Given the following config values are set as admin:
      | phonenumberid   | 1110000000       | local_oksigeniamarandu |
      | connectednumber | +34 900 00 00 00 | local_oksigeniamarandu |
      | connectedname   | Colegio Ejemplo  | local_oksigeniamarandu |
      | verifytoken     | fakeverify       | local_oksigeniamarandu |
    And I log in as "admin"
    When I navigate to "Plugins > Local plugins > Oksigenia Marandu for WhatsApp > Set up WhatsApp" in site administration
    Then I should see "First part done: connected with Meta."
    And I should see "/local/oksigeniamarandu/webhook.php"
    And I should see "fakeverify"
    When I set the field "mrd-appsecret" to "fake-secret"
    And I press "I have pasted them in Meta: check"
    Then I should see "Meta has not called the address yet."
