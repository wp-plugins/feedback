=== Feedback ===
Contributors: Ahmet Soylu, Steinn E. Sigurdarson, Fridolin Wild
Donate link: http://example.com/
Tags: post, feedback, pingback
Requires at least: 2.2
Tested up to: 2.6.3
Stable tag: 1.0

FeedBack is a standard inspired by PingBack, and designed to establish
relatively secure push mechanisms for feed sharing and management.

== Description ==

FeedBack is a standard inspired by PingBack, and designed to establish relatively secure push 
mechanisms for feed sharing and management. Feedback is standard developed in the frame of 
iCamp project (See http://www.icamp.eu). To be able to understand the point, just imagine you are subscribed to different newspapers,
and each newspaper sends an e-mail to you whenever something new happens, and whenever you open your
inbox you see some mails coming from different newspapers, which means in your inbox you hold an aggregated
content of different newspapers. So, feedback plug-in for Wordpress enables you to use such mechanism for blogs, in our
example the subscription was one way, but in feedback case each blog owner can subscribe and can be
subscribed by others. If you subscribe other people's blogs, whenever a user posts something, your
blog is "notified" and you get the updated content, which enables you to reach an aggregated content
provided by other blogs from your own blog. It is important to note that a blog that you are subscribed
to is called as "Channel", and the content it provides to you is called as "feed", whenever someone
wants to subscribe your blog, he sends you a "subscription request", or whenever someone wants you
to get subscribed to him, he sends you a "subscription offer". Please see the tutorial in plugin folder for more information.


== Installation ==

1. Extract feedback folder into '/wp-content/plugins' directory of your 	wordpress installation. 
2. Cut and paste the file 'feedback-rss2.php' from 'feedback' folder to your wordpress installation folder.
3. Activate the plugin through 'Plugins' menu item of your wordpress admin backend.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.png. After your installing Feedback pluging a new
menu item "Read" appears at menu bar. This shot belongs to the reading pane of the plugin where you aggregated
feeds from different channels are displayed. You can change reading options for this pane, filter out feeds according to
the channel and tags. You can also set your privacy setting for activity logging, if you enable this option you allow system
to log your activities within this plugin for research purposes.
2. This screen shot description corresponds to screenshot-2.png. This screenshot is for your subscription management, 'Pending Offers'
displays the subscribtion requests of other people which means they ask you to subscribe their blogs, you can accept or reject. You can subscribe
other peoples blogs by typing their blog URL into the box under 'Request Subscription'. You can also see the list of the blogs which you are
subscribed.
3. This screen shot description corresponds to screenshot-3.png. This screenshot is for your readers, you can see the list of blogs
which subscribed into your blog, you can cancel these subscriptions etc. You can offer people to subscribe your blog, or you can advertaise
blogs to eachother.
4. This screen shot description corresponds to screenshot-4.png. This screenshot is for Tracing mechanizm and only viewable by the site admin. 
Site admin can see the last 50 activities of people who has this plugin installed if they enabled trace logging.