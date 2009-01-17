<?php
$more = 1;
header('Content-type: text/xml; charset=' . get_option('blog_charset'), true);

?>
<?php echo '<?xml version="1.0" encoding="'.get_option('blog_charset').'"?'.'>'; ?>

<!-- generator="wordpress/<?php bloginfo_rss('version') ?>" -->
<rss version="2.0"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"	
>

<channel>
	<title><?php bloginfo_rss('name');?></title>
	<link><?php bloginfo_rss('url') ?></link>
	<description><?php bloginfo_rss("description") ?></description>
	<pubDate><?php echo mysql2date('D, d M Y H:i:s +0000', get_lastpostmodified('GMT'), false); ?></pubDate>
	<generator>http://wordpress.org/?v=<?php bloginfo_rss('version'); ?></generator>
	<language><?php echo get_option('rss_language'); ?></language>	
	<?php
		global $wpdb;
		
		if( !isset($_GET["category"]) OR $_GET["category"]=='' )
			$qcat ='%%';
		else
			$qcat =addslashes($_GET["category"]);
		
		$posts->items = $wpdb->get_results("SELECT ".$wpdb->prefix."sub_feed_items.*, ".$wpdb->prefix."sub_feeds.channel_name FROM ".$wpdb->prefix."sub_feed_items, ".$wpdb->prefix."sub_feeds WHERE ".$wpdb->prefix."sub_feed_items.feed_id =  ".$wpdb->prefix."sub_feeds.id AND ".$wpdb->prefix."sub_feed_items.id IN ( SELECT item_id FROM ".$wpdb->prefix."sub_feed_tags WHERE feed_tag LIKE '".$qcat."') ");
	
	?>
	<?php while($thepost = array_shift($posts->items)) { ?>
	<item>
		<title><?php echo $thepost->title; ?></title>
		<link><?php echo $thepost->item_uri; ?></link>
		<comments></comments>
		<pubDate><?php echo mysql2date('D, d M Y H:i:s +0000', $thepost->pubdate, false); ?></pubDate>
		<dc:creator><?php echo $thepost->channel_name; ?></dc:creator>
		<?php
		 $cats->items = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."sub_feed_tags WHERE item_id=".$thepost->id);
		 while($thecat = array_shift($cats->items)) { 
		?>
		<category><![CDATA[<?php echo $thecat->feed_tag; ?>]]></category>
		<?php } ?>
		<guid isPermaLink="false"></guid>

		<description><![CDATA[<?php echo strip_tags($thepost->content); ?>]]></description>		
	
		<content:encoded><![CDATA[<?php echo $thepost->content; ?>]]></content:encoded>

		<wfw:commentRss></wfw:commentRss>
	</item>
	<?php } ?>
</channel>
</rss>
