# Changelog

## 0.51.1 (2026-11-21)

Security and privacy, from a review of 0.35 to 0.51:
- A notice declared with 'ownjoin' (the approval of a login) only reaches the chats a user joined with a code of its own
  plugin, never those joined from the account settings or for another plugin (`api::OWNJOIN`). Codes can expire
  (`invite(..., $validfor)`, always a new one); the code of a user lasts a day; whoever has a new phone joined to their
  account hears it by email. `api::own_chats()` and `api::unlink_chat()` to see and take away the chats of a plugin.
- The conversations of the team: being passed one no longer lets you see it for ever; a reminder does not take a
  conversation from whoever has it now; starting a new one does not bring in one of another department; favourites and
  the family card only point to what the person sees; an email shared by several accounts is no person of the chat.
- Opt-outs: joining with the code of a plugin takes back only the «no» to that plugin; the plugins hear the scope of an
  opt-out (`marandu_optout`, sixth argument); a text to every chat of an item skips the chats that said no to it; a number
  only typed in the family file is not shown as that family.
- A send to many is decided once, though two decisions arrive at once; only whoever asked takes it back; the question to
  the others closes.
- The webhook keeps and passes on only what is of the number or the account of the site.
- The privacy provider declares and deletes what the index of the conversations keeps of a number.

Fixes:
- The update to 0.51.0 also closed the questions that still waited: they would go out with buttons that answer nothing.
  A question closed while the queue sends stays closed; pressing a button of a closed one says so on Telegram.
- The closed days of the calendar and the limit of the notices of the chat last as long as they should; Chatwoot down is
  asked again a minute later, not on every page.
- Reminders come back even if the list of Chatwoot fails; a send with nobody to approve it goes to the administrators.
- Logos of a palette (8-bit PNG, GIF), and the logo in the pictures of the page of the channel.
- The guides of the staff, in the language of the person or in English.

## 0.51.0 (2026-11-21)

- For the approval of a login from the phone (the factor of Oksigenia Aty): the label of an answer can come from a value
  of the question ('optionlabels'), as the numbers to choose from; up to four answers, which WhatsApp shows as a list;
  'freeonly', a question that never goes with a template, only inside the 24 hours on WhatsApp.
- A question closes: answered elsewhere (`api::close_question()`) or when its time is over, what has not gone does not go,
  a late answer does not count, and on Telegram its message says «Answered» or «Time is over», without its buttons.
- `api::reaches()`: whether a notice would reach a user now, without sending anything.

## 0.50.4 (2026-11-21)

- Who hears when the month reaches its spending limit or a channel stops: one or more addresses, separated by commas.
- `api::ask(..., $urgent)`: a question that cannot wait, such as whether a login is theirs, goes at once, in the quiet
  hours and over the notices a day too.

## 0.50.3 (2026-11-21)

- The wizard shows the test message that will really reach the phone: on a real account, the general notice of the
  site with its line of test, not the sample of Meta in English.

## 0.50.2 (2026-11-21)

- The PNG copy of a logo drawn in SVG is made: the browser fetched it at an address Moodle does not serve (without its
  size and the revision of the theme), and got nothing.
- The test message of the wizard on a real account: the sample template of Meta only exists in the test accounts; without
  it, the general notice of the site goes, once approved, saying it is a test. The hint about the five phones of the test
  number shows only with a test number.

## 0.50.1 (2026-11-21)

- «May be the same family» once: its block, not a hint in the row of the family as well (Jujol).

## 0.50.0 (2026-11-21)

- «Who is it?» with several marks at once (family, staff, supplier, former pupil, other): each with the line it needs
  (what they do, what they offer and their company, their year, what they are for the centre), written where it is
  read and kept by itself; the face in the lists, the first marked or the one chosen; the family of the records comes
  by itself. The lists, the header of the file and the address book show every mark (Jujol's mock-up).
- A change of a contact made in Chatwoot itself reaches the conversations of it, by its webhook.

## 0.49.1 (2026-11-21)

- Choosing who sees the spend and approves keeps each touch by itself, says who sees it now, and never leaves it to
  nobody; «Done» folds it (Jujol).
- Why a send waits comes from the same figure as the bar: the budget spent already, or spent with this send.
- The − and + of the plates of hours and of the steps, drawn instead of letters, so that they sit in the middle.

## 0.49.0 (2026-11-21)

- The sends to many: a plugin asks what one would cost (`api::bulk_quote()`) and asks for it (`api::bulk_request()`);
  it goes now by the usual queue or, if it costs more than the threshold of the site or the month has no budget left,
  waits for approval, which comes from the bell or from Telegram (approve, reject with its why, or send only by
  Telegram, free). `api::bulk_state()`, `api::bulk_cancel()` and the callback `marandu_bulk_decided`. Only to whoever
  joined and did not say no.
- What WhatsApp costs this month, read every hour from Meta (`pricing_analytics`): on the home of the chat, by
  category and day by day, against the budget of the month, with a word at 80 % and 100 %; the threshold and the
  budget with − and +.
- Only the people the site chooses see the spend and approve (from the home, by whoever administers the platform);
  until it chooses, whoever has `local/oksigeniamarandu:bulkapprove`.
- `api::notify(..., $only)`, to send by one channel only.
- On a phone, each department of the home in its own lines.

## 0.48.1 (2026-11-21)

- The label of the card of leaving, shorter so that it keeps its size: «Para volver, escríbannos» (Jujol).

## 0.48.0 (2026-11-21)

- The confirmations, with a card: whoever joins, changes channel or leaves gets, in the chat, a card with the colour,
  the name and the logo of the organization, and a warm text below it. Without the cards (switched off, a server
  without GD, a language with letters the font has not), the text goes alone. A second code from the same chat is not
  welcomed twice.
- The logo of the site on every card, also on those of the channel; a logo drawn in SVG gets a PNG copy from the
  browser of whoever administers, the first time they open a page of Marandu.
- `api::confirm_channel()`, for the plugins that let a person choose their channels, and `api::set_optout()` with
  only one channel.
- Writing again takes back a no only from the day after: the thanks that answers the confirmation is not a change of
  mind. The opt-in word works at once.
- The name of the organization inside a sentence («del Colegio Monte Lirio»), on the page of the channel and with
  `cli/setup.php --org-of`; and `--confirm-cards`.

## 0.40.1 (2026-11-20)

- The review of the card of the family: the legend shows the stages together, by age, and the shapes apart; the foot
  of the card says the number matches but nobody confirmed it; «this chat» only when the family has more than one adult;
  and the thread stays at its end when the box to write grows, as when a file is chosen.

## 0.40.0 (2026-11-20)

- Who writes, from the common record of families (Oksigenia Family, 0.3.34 or later, if the site has it): the list
  says «Family of Pablo (5.º B) and Sara (1.º A)», the creature of the contact has a ring with the colour of the stage
  of each child, and the card shows the family, its children with their group and stage, and its other adults with
  their conversation. A number nobody confirmed still counts, as a hint, and the card says so.
- The stages are data of each organisation (`cli/chat.php --stages`): their name, colour (also for a dark page) and how
  a group is known to be of each, by the stage the record guesses from it or by a pattern; «PRI 5º B» is shown as
  «5.º B». The legend at the foot of the trays shows the stages of the organisation.
- The list shows what was said, without the signature on top.

## 0.39.0 (2026-11-20)

- The people of the team hear on the phone (the app) and in the bell of the site, never by email: whoever has a
  conversation, when the contact writes; the people of its department, when nobody has it and the contact starts
  waiting; whoever it is passed to; whoever is named in a note, and everyone in a department named in one (as a bot
  that warns of long waits does). Only in the hours of the team, but what is passed or named; never to who did it;
  once in ten minutes for the same conversation. The notice opens the conversation, also inside the app.
- Files: the page sends a picture, an audio, a video or a document (up to 16 MB) with its text and signature, or in a
  note; and what comes is served by the site to whoever sees the conversation, so it opens inside the app too.
- In the Moodle app, the link of a notice of the conversations opens the page inside the app with the session
  started; no entry of its own in the menu.

## 0.38.1 (2026-11-20)

- The signature of each person is the one they have in Chatwoot (their profile), as it is: some sign only with their
  department. Only whoever has none signs with their first name and their first department. It needs the platform app,
  which reads each profile with its own token.

## 0.38.0 (2026-11-20)

- For a number that joins the API from the app of WhatsApp Business (coexistence): the site keeps, as they come, the
  chats and contacts Meta sends once in the day after (`history` and `smb_app_state_sync`), and `cli/history.php` asks
  Meta for them, says how far they came and writes them in one file, in order and without repeats.
  `cli/chatwoot/import_history.rb` brings that file into an inbox of Chatwoot: each chat a resolved conversation with
  every message at its date, written directly, without webhooks, bots or sending to WhatsApp; it can run twice.
- What a person writes in the app of WhatsApp Business of the number (Chatwoot keeps it as an echo) is an answer of a
  person in the conversations: it ends the wait, and says it came from the phone.
- `cli/chat.php --status` tells the people of the team apart from the automatic users.

## 0.37.2 (2026-11-20)

- The lines of Chatwoot about what happened in a conversation are said as the page says things, with first names and
  the departments as people write them: «Ana passed it to Recibos», «Luis took it», «Ana marked it resolved». The
  «unassigned» that comes with passing to a department does not show; lines it does not know stay as they came.
- The name at the foot of the trays is the first name and the first surname, whatever the order of names of the site.
- The card of admissions of Eguahe goes beside a conversation only for the people of its team, below «Who is it?», and
  measures what its content.
- The background with the colours of the organisation keeps thin stripes far apart with fewer than four colours.

## 0.37.1 (2026-11-20)

- The review of the page of the conversations: the gaps of an approved message go inside its sentence, as the family
  reads it; no space between a mention and the sign after it; who read it sits on the paper, not across the margin of
  the notebook; the signature of the person fits in two lines; and on the phone the saved answers keep their name.

## 0.37.0 (2026-11-20)

- The conversations of the team (`chat.php`): where the people of an organisation answer its WhatsApp, over an account
  of Chatwoot. Trays for what is yours and what nobody has yet, and one for each department; each list in three parts
  (to answer, waiting for the contact, resolved today); answering with your signature on top, internal notes with
  mentions, keeping a conversation, passing it to a department or to a person, resolving it; the window of 24 hours,
  after which only an approved message of the inbox can be sent; saved answers; who of the team read the last message.
- Departments are the teams of Chatwoot, and a person is the user of Chatwoot with the same email as in Moodle. The site
  filters who sees what: the administrators of the account and whoever has `local/oksigeniamarandu:chatall` see
  everything; the rest, their departments and what they have. An administrator of the site who is not a person of the
  account only reads.
- The wait counts from the first message of the contact after the last answer of a person (a bot does not end it), and
  only in the hours of the inbox, without the closed days of a calendar of the site (an iCal; its events starting with
  «Closed» or «Bank holiday», words of its own).
- With a platform app of Chatwoot, each person answers, keeps and passes as themself there too, with their own token;
  without one, the site writes with the token of the connection and the signature says who it was.
- An index of the conversations in Moodle, kept up to date every minute, from the page every few seconds and, with the
  webhook of the account, at once (signed and checked). Messages stay in Chatwoot.
- `cli/chat.php` connects the account (tokens from a file or the standard input), creates the webhook, says which
  departments are internal and which users are a bot, and the calendar of closed days.
- The card of the contact can show the card of another plugin in a frame, as a «Dashboard App» of Chatwoot gets it:
  the one of admissions of Oksigenia Eguahe.

## 0.36.0 (2026-11-20)

- What the site answers itself on WhatsApp (the words to join and leave, with or without a code, and the buttons of its
  questions) is no longer passed on to the other address of the webhook: a bot of the shared inbox would answer it too.
  Everything else goes on as it came.
- The web service `local_oksigeniamarandu_whatsapp_context`, for the bot of a shared inbox: what the site knows of a
  WhatsApp number. Whom it joined with, the last notice the site sent it (plugin, notice, label and when) and, for a
  message given, which plugin of the site answered it or kept it. So the inbox sends a reply to a notice straight to the
  people of that plugin, and keeps quiet where a plugin already answered. It needs the new capability
  `local/oksigeniamarandu:inbox` and comes in the service «Oksigenia Marandu, shared inbox».

## 0.35.0 (2026-11-20)

- «Also pass on to», in the settings of the webhook: an address that gets everything Meta sends to the site, as it
  came and with its signature, after the site does its part (joining, leaving, the statuses of what it sent). For the
  shared inbox where people answer the chats, for example: Meta calls only one address per number. If the address does
  not take it at once, an ad hoc task tries again later.
- Meta sends what it says of the templates (approved, rejected, a new category) only to the webhook of the app, whatever
  account it is about, even when the account or the number has a webhook of its own. A site now takes only what is
  about its own account: two sites with the same app and templates with the same names mixed them up. And the hourly
  task asks Meta how the templates of the site are, so a site with a webhook of its own knows when they are approved.

## 0.34.0 (2026-11-18)

- `cli/setup.php`, for whoever hosts many sites: connects the bot of Telegram with its token from a file or the standard
  input (never from the arguments), switches on the notices of the plugins given for a channel, and shows how the site
  is. The same checks as the page: the same bot with a new token keeps everyone, and another bot, when people joined
  the one of now, is changed only from the page.
- What families read (the page to join by WhatsApp, the replies, the menu of the bot and its answers) in Portuguese of
  Portugal and of Brazil, and in Arabic, for the families who choose those languages. Moodle uses them when the site
  has the language pack.
- The page to join by WhatsApp, when nothing changes, says to ask whoever sent the link, not the school office.

## 0.33.1 (2026-11-16)

- Meta now keeps the number and the templates of a business in two accounts: the WhatsApp account and the messaging
  account. The wizard checks that the token reaches the account of the templates too, and if it does not, says what to
  give the system user. The help on where the permanent token comes from lists the three things the system user needs,
  with full control: the app, the WhatsApp account and the messaging account.

## 0.33.0 (2026-11-14)

- A template Meta moved to marketing keeps that category, as Meta does not let an approved template change it. When its
  text changes, the new text goes as a new utility template, under the same name with _2, _3…; the old one goes on
  sending while Meta reviews it, the new one takes its place once approved, and the old one is deleted from Meta in the
  next hourly run. The wizard says it: «New text in review: the previous one goes meanwhile».
- An approved template whose values no longer match the text of its notice is not used until Meta has the new text:
  Meta would refuse it.
- The general notice reads as a notification of the account: «There is a new notification in your {{1}} account».

## 0.32.1 (2026-11-13)

- «The day of the channel» says «one every hour», not «one every 1 hour».
- Opting out a Telegram chat by hand: «The chat no longer belongs to that person», also in the record.

## 0.32.0 (2026-11-13)

- The page of the channel without a form, by Jujol: each card saves itself at a touch, and with JavaScript changes in
  its place. «What goes to the channel» saves each kind as it is touched (before, it waited for «Save changes»). «The day
  of the channel» draws the 24 hours with the time of review, that of the channel and the posts of a busy day; hours are
  plates with half an hour less and more, days are sheets of a calendar, the gap between two posts is a row of chips.
  What is set once goes in five folded cards (calendar, weather warnings, place and sky, links, cards), each saying in a
  line what it has. A card only saves what it sends: saving one no longer empties the others.
- «Quiet hours and limits», also step 5 of the wizard: the week as seven rulers of 24 hours with the quiet dark as
  night, the name of each day as the switch that quiets it whole, the notices a day and the money of a month with less
  and more, and what does not wait as switches. At most 20 notices a day for each person.
- Opting out by hand: «How did they ask?» with a button for each way, which opts out at a touch; «Another reason»
  folded, with its field. A Telegram chat is asked and told as a chat, and comes back from Telegram, not from WhatsApp.
- Every setting of the administration has its value from the install on, and those a new version brings from its
  update: Moodle no longer stops whoever installs the plugin on its page of new settings with the fields of Meta.

## 0.30.0 (2026-11-08)

- `api::channel_members($from, $to)`: how many people the channel had each day it was counted, for a series over time.
- The queue keeps the item a notice went to, and `api::notice_stats()` takes a list of items, to count those of one
  centre of a plugin.
- The card of «Your work» also shows the life of the channel: how many joined this week, and what went out this week with
  its reactions.

## 0.29.0 (2026-11-08)

- The reactions to the posts of the channel, as Telegram counts them for its bot (anonymous, by emoji, a few minutes
  late): the bot asks for them from this version on.
- The short link of a post of the channel goes through the link of the post, so that its visits are always counted by
  Marandu, with a shortener or without one.
- `api::channel_stats()`: how the posts of a plugin did (when, visits, reactions and the people of the channel that day);
  `api::notice_stats()`: its notices by notice, channel and day (queued, sent, delivered, read, clicked and failed). All
  summed, nothing of any person. Telegram does not tell bots how many saw a post, nor whether a notice was read.

## 0.28.0 (2026-11-08)

- Two cards more, by Jujol: the weather warning (its level in a badge of its own colour, the times of the episode and the
  areas) and the work of a class (the photo hung whole from the line of the class with two pegs, the title and the
  group). The weather warnings and the work of a class go with them, still and animated.
- «What goes to the channel»: a kind without a card has the picture of a message instead of an empty box.
- «Waiting for your review» says, under each post, its kind and when it goes out.

## 0.27.1 (2026-11-08)

- The picture of «To the channel» has its address before a photo is chosen, as HTML wants.

## 0.27.0 (2026-11-08)

- The page of the channel, after the review of Jujol: what waits for a review shows the card it will have (larger at a
  touch; above its buttons on a phone); each kind of «What goes to the channel» has its card, small, with the colour of
  the organization; a bar to save appears as soon as something changes, and the browser asks before leaving with changes
  not saved; the hours do not break on a phone and the days go below; the marks of the links show only when they are
  measured; «Use the location of this device» fills the place; the levels of the weather warnings have their colour; the
  last reading of the source has its own line.
- «To the channel»: the photo first and large («Take a photo or choose it», with the camera on a phone), at once in its
  place with the title and the group under it as the channel will show them; beside the fields on a wide screen.
- A plugin can say the card of a kind of post: `'card' => 'open_day'` in its declaration.

## 0.26.0 (2026-11-08)

- `api::notify()` and `api::notify_child()` take a last argument, `string $lang`: the language to write the notice in,
  such as that of the family, if the site has its language pack; a person who chose a language on Telegram gets theirs.
  `api::language($lang)` says which language Marandu will write it in, so that the plugin writes the values of the notice
  (dates, names) in the same. Moodle reads the strings of a plugin in a language only when the site has its pack.

## 0.25.1 (2026-11-08)

- «Your work» says that a post waits for your review only once it reached you on Telegram (in the hours of review, or at
  once if it is urgent); until then, its line says when it will («2 to review tomorrow at 8:00»). Before, it said so out of
  the hours of review too, and whoever reviews looked for it on Telegram in vain. Without anybody who reviews on Telegram,
  it is on the page of the channel, in the hours of review.
- The page of the channel says the pass of the station that goes to the channel, not the highest of the week.

## 0.25.0 (2026-11-08)

- On a site with WhatsApp that already sent its texts from the wizard, the templates of the notices a plugin brings in a
  new version go to Meta by themselves, every hour, without going back to the wizard; and the new text of a template
  that changed goes as an edit of the one Meta has (approved, rejected or paused; one still in review waits).
- A text Meta would refuse (it starts or ends with a value, or its values skip a number) does not go: the wizard says why
  and «What notices go out» marks it, for whoever makes the plugin to fix it.

## 0.24.0 (2026-11-08)

- Work of a class for the channel: in a course, «To the channel», for whoever teaches it
  (`local/oksigeniamarandu:share`): a photo of a piece of work, its title, the group and a few words, without names. The
  photo is drawn again before it is kept (straight, at most 1600 pixels, as a JPEG, without what the camera writes in it).
  It always waits for a review, whatever the site says; the log says who sent it, the post does not.
- A plugin can declare a kind of post that always waits for a review: `'always' => true`.
- Every change on hover is for a mouse only (`@media (hover: hover)`): on a tablet, a tap no longer leaves a button dark,
  nor the empty background of Bootstrap.

## 0.23.0 (2026-11-08)

- The sky over the place of the site, for the channel, with the sky card: the evenings the International Space Station
  passes high (30° or more), lit and long enough (three minutes or more) to see it with the naked eye, one a week at most,
  with its time, where it appears and where it goes; the full moon, with the time it rises; and the nights of the great
  meteor showers of the hemisphere of the site. The station is worked out on the server with SGP4 from its elements,
  downloaded once a day (CelesTrak unless the site says another source); the Sun and the Moon, with the formulas of low
  precision of the Astronomical Almanac. Checked against the library sgp4 and against Skyfield.
- The page of the channel says the next full moon and the next good pass of the station.
- The times of the weather warnings, in the time zone of the site, whoever prepares them.

## 0.22.0 (2026-11-08)

- The official weather warnings of the place of the site, for the channel: from any source in the CAP format (an Atom or
  RSS feed, such as those of MeteoAlarm or the National Weather Service, or one alert), read every quarter of an hour. A
  warning is of the site by the name or the code of its area, or by the place of the site inside its outline; from the
  level the site says (moderate, severe or extreme). Warnings whose times touch go together in one post, under the most
  severe, with the times of each, the area, the source and its link; in the language of the site when the whole alert
  brings it. The severe and the extreme are urgent: their review comes at any hour. What the source no longer brings does
  not go if it is still waiting, and what it cancels is taken out of the channel too.
- The place of the site (latitude and longitude), on the page of the channel, for the warnings and for the sky.
- `api::channel()` and `api::channel_link()`: the channel as anyone may find it, with its link, its name and its members,
  for a QR on a screen.
- A post that whoever reviews skipped, or that could not go out, no longer comes back unchanged when its plugin posts it
  again with the same key: before, «No class tomorrow» could reach review again every hour after a «Skip».

## 0.21.1 (2026-11-08)

- The things to do on the dashboard carry `due`, the time of their deadline: for a post to review, when it goes out if it
  is said yes; for what stops the channel, now. They are sorted by it in each level, and what has none goes after. The
  key of the card is `local_oksigeniamarandu-channel`, unique across the site, as the block asks.

## 0.21.0 (2026-11-08)

- The card of the channel on the dashboard: `local_oksigeniamarandu_oksigenia_work($userid)` gives whoever may review the
  channel a door to its page, a line with its figures and what is waiting for them (each post to review, a channel that
  cannot post, a bot with a problem, posts that did not go out), urgent first. It is kept for a minute and forgotten as
  soon as a post changes.
- Whoever may review the channel (`local/oksigeniamarandu:channel`) without running the site opens its page too, sees what
  is waiting and decides it there; the settings stay for whoever runs the site.

## 0.20.0 (2026-11-08)

- The cards of the channel: Marandu draws each post as a still card and, with ffmpeg 6.1 or later, an animated one, from
  seven templates, with the colour of the theme and the name of the site unless the page of the channel says others.

## 0.19.0 (2026-11-08)

- The calendar of the channel: «This week» on Monday and «No class tomorrow» on the eve of a day without class, from an
  iCal or the events of the site. Measured links: UTM marks and, optionally, a shortener with the API of YOURLS.
  Animations as MP4.

## 0.18.1 (2026-11-08)

- The review says when a post really goes out, with the hours of the channel.

## 0.18.0 (2026-11-08)

- The channel of Telegram of the site: what goes by itself and what is reviewed first, in the chat of whoever reviews,
  in the hours of review; the announcements of the front page go by themselves.

## 0.17.0 (2026-11-08)

- Areas for the kinds of item of a plugin (`area`, last parameter of the API), and notices that follow another one
  (`'follows'`).

## 0.16.0 (2026-11-07)

- What people write of their own reaches the plugin of their code by the hook `message_received`, and a plugin answers
  with `api::send_text()`. A switch for the family records.

## 0.15.0 (2026-11-02)

- Questions with buttons: a plugin declares a notice with `'options'` and sends it with `api::ask()`. On WhatsApp, the
  answers are the quick replies of its template, or reply buttons inside the 24 hours; on Telegram, a button each. An
  answer counts only from the number or chat the question went to, is kept with the ids and times of both messages, and
  reaches the plugin with the hook `\local_oksigeniamarandu\hook\answer_received`, which can say what to answer.

## 0.14.2 (2026-11-02)

- The panel: who was already active and joins again no longer shows as a new sign-up (the log keeps it, as proof). The pill
  of the quiet hours and the limits is on the panel. «The latest» goes when nothing has failed, and that card fills its box.
  On a phone, the number next to «today» on the axis of the month gives way, so they do not overlap.

## 0.14.1 (2026-11-02)

- The clients of Telegram and WhatsApp load Moodle's class of requests themselves: it worked because other plugins happened
  to load it, and in a script without them it failed. Found with the first real card, sent from the command line.

## 0.14.0 (2026-11-02)

- `api::notify()` and `api::notify_child()` take a last argument, `array $rich`: on Telegram, the notice goes as a card (a
  rich message) with its title, image, list, text and the button inside. The image is a URL of the site: Marandu reads it
  and uploads it once for each bot, so it also works on a site Telegram cannot reach. If Telegram refuses the card, the
  text of the notice goes. Without it nothing changes, and WhatsApp ignores it.
- With `local_oksigeniafamily` 0.2.2: the code of a second student from the same chat of Telegram joins the adult the chat
  already stands for, instead of creating another one.

## 0.13.6 (2026-11-02)

- An urgent notice, by its plugin or by the site, and one that each person asks for, neither stop at the daily limit nor
  count towards it: a family that asked for three menus still hears at once that a dish changed. The page of the limits
  says so.
- With `local_oksigeniafamily` 0.2.1: WhatsApp vouches for the number that writes a student's code, so it goes to the
  adult who has that number instead of creating another one each time. A number only typed on its claim page stands for
  nobody until the school confirms it.
- The daily check of the family records no longer drops what joined with the adult's own code (`api::adult_channels()`)
  when the records have another number, or none. It stays until the adult leaves.

## 0.13.5 (2026-11-02)

- On Telegram, a notice of several lines goes with its first line in bold. WhatsApp does not change.

## 0.13.4 (2026-11-02)

- A plugin can declare a notice with `'askedfor' => true`: each person asks for it at a time they choose, so the quiet
  hours do not hold it back. It is not urgent, so it keeps the choice of notices without sound. The page of the limits
  says which ones are.
- `api::notices_on()`: whether a plugin has any notice switched on for a channel, so that it does not offer a channel
  by which nothing would reach the person.

## 0.13.3 (2026-11-02)

- The texts speak of people, not of families, wherever the notices can be for anybody: an academy or a company fits too.
  The family records keep their name where they are the subject.

## 0.13.2 (2026-11-02)

- `api::item_channels()`: where an item of a plugin (a family, for example) gets the notices, and the links to join
  the channels still to join.

## 0.13.1 (2026-11-01)

- The icon of Oksigenia Marandu, by the house: "the centre notifies", a speech bubble with a roof and a bell.

## 0.13.0 (2026-10-31)

- "Quiet hours and limits", a page of its own, for every channel; the money of the month, only with WhatsApp. A site
  that never set them gets those the wizard proposes: no notice at night or at the weekend, three a day at most.
- Fix: the notices of step 4 of the wizard and the urgent ones of step 5 never saved (Moodle drops the keys of a form
  array that have a slash). Fix: the upgrade of 0.11.0 shipped without its step.

## 0.12.0 (2026-10-31)

- Telegram: "Share my number", to join one's family without a code, when the site has the family records; the number
  is only compared, never kept.
- Fix: the daily check of the family records dropped the family links of every Telegram chat.

## 0.11.0 to 0.11.3 (2026-10-31)

- The panel with more life, from the mock-up of the house: the state with its scene and each channel; the way to the
  first notice on a brand-new site, and the firsts celebrated once; today, the month day by day and each notice, by
  channel; what WhatsApp costs, apart. Today ends with the last notice that went out.
- Each channel has its own notices switched on, on a page of its own, "Which notices go out".
- The way back to the panel from the wizard and the page of Telegram; room under the header; WhatsApp answers in the
  24-hour window are free only up to 1,000 a month.

## 0.10.0 (2026-10-31)

- The menu of the bot of Telegram, in each language of the site: help, whom the notices are about, their language,
  without sound except the urgent ones, and leaving. The bot answers at once, in the reply to the webhook.

## 0.9.0 and 0.9.1 (2026-10-31)

- The page of Telegram in three moments, from the mock-up of the house, and changing the bot: a new token of the same
  bot keeps everyone; another bot asks first and can tell the people of the old one where to go.
- The list of numbers and chats can show one channel; the panel title names the channels the site has.

## 0.8.1 (2026-10-31)

- The page of Telegram, from the reread by design: the steps to make the bot numbered, with the link to @BotFather and
  a button to copy /newbot; the note on privacy before connecting; the form of the token checked as it is pasted; the
  steps that come after. The Telegram pill of the panel has its own drawing, a paper plane.

## 0.8.0 (2026-10-31)

- Telegram, the second channel, with the bot of each site:
  - A page connects the bot with its token: the plugin checks it with Telegram and tells it where the webhook is, with
    a secret it sends back. Whoever sets it up joins from their phone and sends themselves a test message.
  - People join by opening the bot with its link and a code (`t.me/<bot>?start=<code>`), the same as ALTA on
    WhatsApp, also with a student's code of the family records. «/stop», the opt-out word or blocking the bot opts out.
  - The same queue, quiet hours and limit a day: a notice goes as its text, with its link as a button. It is free, with
    no templates and no 24-hour window.
  - Each channel stops apart: a token that Telegram no longer takes stops Telegram, and WhatsApp goes on.
  - The panel and the list show Telegram: its stop, its line in the month and the people who blocked the bot.
- For a screen where a person chooses where to get the notices: `api::channels()`, `join_url()`, `user_channels()`,
  `adult_channels()` and `user_joined()`.

## 0.7.0 (2026-10-31)

- The family records of Oksigenia Family (`local_oksigeniafamily`), when the site has them, and nothing changes
  without them:
  - ALTA with a student's code of the records joins the number to the student's family; ALTA alone, from the number
    the school has for an adult, joins it to that adult. Being in the records never sends anything: only writing does.
  - `api::notify_child()` sends a notice about a student to the numbers of the student's family.
  - When an adult leaves the records or their phone changes, the old number stops standing for them at once, and a
    daily task checks it again.
- `api::unlink()`, for a plugin to stop a number standing for one of its items or users.
- A number that writes too many wrong codes waits a quarter of an hour; it counts by number, since on WhatsApp
  everything arrives from the addresses of Meta.
- A code may come in two halves, as a letter prints it: «ALTA K7M2 Q9XA».
- The name is Oksigenia Marandu, without the channel.

## 0.6.1 (2026-10-24)

- The reread of the panel by design: the state in bold, no underline on the links of the header in any mode, one or
  many in the counts of people («1 pendiente», «9 pendientes»), and the reason of an opt-out in lower case after the
  colon.

## 0.6.0 (2026-10-24)

- The panel, the first page of the plugin: whether the notices go out and, if not, the one thing to do (a new token,
  the restriction or the payment in Meta, the limit of the month); today, from sent to read, and what waits and why;
  the month against the limit, at this pace; what failed, with the reason in plain words; who joined or left, and how;
  and how many notices of each kind went out.
- The list of numbers: search by a part of the number or by name, filters by status with their counts, twenty at a
  time, one card per number on a phone, and opting a number out in its own row, with the reason.
- Each plugin can say what it is about and what its items are, in its own words (`marandu:label`, `marandu:item`),
  for the panel and the list.
- A notice that fails keeps the error code of Meta, for the panel to tell why; the page where a family joins no longer
  breaks if the plugin that asked for the code has been uninstalled.

## 0.5.0 (2026-10-24)

- The queue tries again after errors that pass (Meta busy, too many messages, the network): after 1, 5, 15 and 60
  minutes, and then the notice has failed.
- A problem of the account (an expired token, permissions, a restricted account, payment) stops the queue without
  spending its notices: the wizard and the list of numbers say so, whom the site said gets an email once a day, and
  the queue goes on by itself when the account works again.
- When Meta says the 24 hours after the family wrote have passed, the notice goes with its template; a template Meta
  pauses or disables stops being used, and its notices wait.
- What Meta says of the templates, as its webhooks write it: regional languages with a hyphen (es-MX), templates that
  come back (reinstated) or are deleted, the category of each status, and a change of category announced a day
  before. The example of the button is the end of its link, as Meta asks.
- The list of numbers can opt a number out when a family asks the site; it stays in the record of consent, with who
  did it.
- The wizard and the page where a family joins take their colours from the theme, so they follow the light and dark
  modes of Moodle 5.3; the QR code stays dark on white.
- The default version of the Graph API is v26.0; sites that kept the old default move to it.

## 0.4.1 (2026-10-24)

- Wizard: on a phone, the progress keeps the same right margin as the content.
- Wizard: the test number is shown as the person wrote it, in the test step and in the summary.

## 0.4.0 (2026-10-24)

- Notices: each plugin declares its own in its lib.php, with their texts in its language packs; Marandu brings a
  general one («you have a new notice from the site», with a button to read it).
- Wizard step 3: the texts go to Meta for review as utility templates, one per notice, with a button to the site; the
  wizard shows what Meta says (in review, approved, rejected and why, moved to marketing), from the webhook or on
  request.
- Wizard step 4: which notices go out by WhatsApp, with what they would cost a month.
- Wizard step 5: the limits as sentences: spending a month (and who is told when it is reached), notices a day for
  each family, quiet hours and days, and the notices that are urgent.
- The queue: `api::notify()` queues a notice for everyone who joined for an item or a user; a task sends it when its
  template is approved, outside the quiet hours and within the limits. Inside the 24 hours after a family wrote, it
  goes as plain text, which is free. Delivered, read and failed come back from the webhook, and the button of each
  notice goes through the site.

## 0.3.2 (2026-10-22)

- Wizard, step 1: each detail of the second part in one row, shorter texts and a wider column for the fields, so that
  the main button stays in view on a small laptop screen; the header row breaks whenever the title does not fit beside
  the progress.

## 0.3.1 (2026-10-22)

- Wizard: the title of the step is the heading of the page and the progress sits beside it, without the admin search;
  on a narrow screen the drawing of Meta folds before the fields, and long addresses no longer push the page wider
  than the phone. The drawing shows again which token not to use.
- Family page: one centred column on a computer too, a smaller QR code without a gap below, «Save the number» on one
  line, and an emblem for a link that does not work.

## 0.3.0 (2026-10-22)

- A set-up wizard instead of the plain settings: connect the account (checked with Meta, which answers with the number
  and the name of the site), let Meta reach the site (callback URL and verify token to copy, the app secret, and a
  check that Meta has called), and send yourself a test message that shows when it is sent, delivered and answered.
- The private page where a family joins, on the phone and without a Moodle account: it opens WhatsApp with the message
  already written, shows it as a QR code on a computer, changes by itself once the message arrives, and then offers
  the number of the site as a contact and the way to leave.
- `api::signup_url()` for that page, and the callback `<component>_oksigeniamarandu_label()` so that a plugin names
  whom a code stands for.
- Spanish texts for families, addressed as «tú».

## 0.2.0 (2026-10-22)

- Numbers and their consent: a number receives nothing until it opts in, and every opt-in and opt-out is kept as
  proof.
- Joining and leaving by WhatsApp: writing the opt-in word (ALTA or JOIN by default), with or without a code, or the
  opt-out word (BAJA or STOP). The words can be changed; case and accents do not matter. Each one gets a reply.
- The webhook Meta calls, checked with the verify token and the signature of the app secret.
- An API for other plugins: a code and a link that opens WhatsApp with the opt-in word and the code written, so that a
  family or a user joins and gets linked to what the code stands for.
- A list of the numbers, their status and whom they stand for.
- Full privacy provider: the numbers of a user can be exported and deleted; WhatsApp is declared as an external
  location.

## 0.1.0 (2026-10-22)

- Base of the plugin for Moodle 4.5 to 5.3, with English and Spanish language packs.
- The WhatsApp account of the site: phone number ID, WhatsApp Business account ID, access token (stored encrypted)
  and Graph API version.
- A page to send a test message (an approved template) to the administrator's phone, with clear messages for the
  usual errors of Meta: expired token, phone not on the list of the test number, template that does not exist.
- Client of the WhatsApp Cloud API, tested with a fake that answers for Meta.
- Checks of every Oksigenia repository: moodle-plugin-ci on PostgreSQL and MariaDB, Gitleaks, and the commit-msg hook
  plus its CI job against AI signatures.
