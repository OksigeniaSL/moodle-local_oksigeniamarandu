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
        return [{
            action: async(siteId) => {
                const site = await that.CoreSitesProvider.getSite(siteId);
                await site.openInAppWithAutoLogin(url);
            },
        }];
    }
}

that.CoreContentLinksDelegate.registerHandler(new MaranduChatLinks());
