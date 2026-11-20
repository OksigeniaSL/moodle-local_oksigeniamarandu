// What the Moodle app (and Aty) runs when it starts: the link of a notice of the conversations of the team opens the page
// of the conversations inside the app, with the session started, instead of the site in a browser.
const that = this;

class MaranduChatLinks extends that.CoreContentLinksHandlerBase {
    constructor() {
        super();
        this.name = 'local_oksigeniamarandu_chat';
        this.pattern = /\/local\/oksigeniamarandu\/chat\.php/;
    }

    getActions(siteIds, url) {
        // The app hands the address relative to the site («/local/…/chat.php?c=4»): made whole again with the site's,
        // which may live in a folder.
        return [{
            action: async(siteId) => {
                const site = await that.CoreSitesProvider.getSite(siteId);
                const whole = /^https?:\/\//i.test(url) ? url
                    : site.getURL().replace(/\/+$/, '') + '/' + url.replace(/^\/+/, '');
                await site.openInAppWithAutoLogin(whole);
            },
        }];
    }
}

that.CoreContentLinksDelegate.registerHandler(new MaranduChatLinks());
