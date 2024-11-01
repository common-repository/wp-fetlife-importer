=== Plugin Name ===
Contributors: meitar
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=meitarm%40gmail%2ecom&lc=US&item_number=WP%20FetLife%20Importer&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted
Tags: importer, wordpress, FetLife
Requires at least: 3.5
Tested up to: 3.9.2
Stable tag: 0.2.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Import your FetLife Writings and Pictures to your WordPress blog as posts.

== Description ==

The WP FetLife Importer is a [WordPress](https://wordpress.org/) plugin offering an easy way to import your [FetLife](https://en.wikipedia.org/wiki/FetLife) Writings and Pictures to a WordPress blog. If you're using a [WordPress.com](https://wordpress.com) blog or want to move your FetLife content to a non-WordPress blogging platform, try [the FetLife to WXR Generator instead](http://fetlife.maybemaimed.com/fetlife2wxr/). For widgets, shortcodes, and other WordPress bells and whistles that show FetLife data, try the [WP-FetLife](https://wordpress.org/plugins/fetlife/) plugin. :)

The following table describes the conversions WP FetLife Importer makes from your FetLife content:

<table summary="FetLife to WordPress conversion table.">
<tr><th>FetLife content type</th><th>WordPress content type</th></tr>
<tr><td>Profile</td><td>Author</td></tr>
<tr><td>Writings</td><td>Posts (with categories)</td></tr>
<tr><td>Pictures</td><td>Attachments</td></tr>
</table>

Yes, WP FetLife Importer also imports comments.

This plugin is free software, but please consider [making a donation](http://maybemaimed.com/cyberbusking/) if you found it useful.

== Installation ==

1. Upload the `wp-fetlife-importer` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to the Tools -> Import screen, click on FetLife

== Screenshots ==

1. Enter your FetLife connection details on the WP FetLife Importer screen, and optionally specify a proxy URL. Once entered, you're ready to import!

== Frequently Asked Questions ==

= Why aren't some of my Pictures being imported?  =

Most likely, it's because you haven't written any captions on your FetLife Pictures. You need to make sure that each of the pictures on your FetLife profile has its own (unique) caption, so WP WordPress Importer won't think it's already imported that picture.

= Why is FetLife so awful? =

Because [FetLife is a corporation that doesn't give a shit about you](http://disruptingdinnerparties.com/2013/05/08/got-consent-3-fetlife/). I'm serious. Check out the following resources:

* [The privacy information FetLife doesn't want you to read](http://maybemaimed.com/2012/09/26/the-privacy-information-fetlife-doesnt-want-you-to-read/)
* [Help FetLife's rape culture FAADE away](http://days.maybemaimed.com/post/39785638940/last-october-i-introduced-the-fetlife-alleged)
* [FetLife iCalendar: Hate FetLife, but need to know what's going in your Scene? There's an App For That.](http://days.maybemaimed.com/post/44234308620/fetlife-icalendar-hate-fetlife-but-need-to-know)
* [Tracking rape culture's social license to operate online](http://maybemaimed.com/2012/12/21/tracking-rape-cultures-social-license-to-operate-online/)

== Changelog ==

= 0.2.3 =
* Spanish translation (thanks, Andrew Kurtis).
* Fix minor bug in importer timeout.

= 0.2.2 =
* Clarify some features in the documentation.

= 0.2.1 =
* First deployed to WordPress.org's Plugin Repository. :)

= 0.2 =
* Import content posted as FetLife Pictures and comments, too.

= 0.1 =
* Initial release

