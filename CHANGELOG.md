# Changelog

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
