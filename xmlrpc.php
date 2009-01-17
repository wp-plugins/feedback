<?php
/********************************************************************************
Copyright (C) 2007  Steinn E. Sigurdarson, Ahmet Soylu, Fridolin Wild

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*******************************************************************************/

define('XMLRPC_REQUEST', true);
define('MAGPIE_CACHE_ON', 0);

// Some browser-embedded clients send cookies. We don't want them.
$_COOKIE = array();

// fix for mozBlog and other cases where '<?xml' isn't on the very first line
if ( isset($HTTP_RAW_POST_DATA) )
	$HTTP_RAW_POST_DATA = trim($HTTP_RAW_POST_DATA);

include('../../../wp-config.php');
include_once(ABSPATH . 'wp-admin/admin-functions.php');
include_once(ABSPATH . WPINC . '/class-IXR.php');
include_once(ABSPATH . 'wp-content/plugins/feedback/lib.php');

class fb_xmlrpc_server extends IXR_Server {

	function fb_xmlrpc_server() {
		$this->methods = array(
			// FeedBack
			'feedback.offer' => 'this:feedback_offer',
			'feedback.request' => 'this:feedback_request',
			'feedback.notify' => 'this:feedback_notify',
		);
		$this->methods = apply_filters('xmlrpc_methods', $this->methods);
		$this->IXR_Server($this->methods);
	}

	function feedback_offer($args)
	{
		global $wpdb;  
		global $wpmuBaseTablePrefix;              

		if (count($args) != 2)
			return new IXR_Error(101, "Invalid number of arguments");

		$subscription_uri = $args[0]; 	// which feed is being offered
		$target_uri = $args[1];		// where (which user) the offer is being sent


		
		// XXX: if either arg is invalid URI... return another error..
		// if (!is_valid_uri(...
		//	return new IXR_Error(102, "Invalid feed URI");

		$wpdb->hide_errors();
		// check if we're already subscribed
		$sub = $wpdb->query("SELECT feed_uri FROM ".$wpdb->prefix."sub_feeds WHERE feed_uri = '" . addslashes($subscription_uri) . "'");

		if ($sub)
		{
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received feedback offer','','".addslashes($subscription_uri)."','Rejected: Already subscribed!',NOW())");
			return new IXR_Error(103, "Already subscribed");
		}

		$received = $wpdb->query("SELECT feed_uri FROM ".$wpdb->prefix."sub_offers WHERE feed_uri = '" . addslashes($subscription_uri) . "'");
		if ($received)
		{
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received feedback offer','','".addslashes($subscription_uri)."','Rejected: Offer already pending!',NOW())");
			return new IXR_Error(104, "Offer already pending!");
		}

		$wpdb->query("INSERT INTO ".$wpdb->prefix."sub_offers (feed_uri,receive_time) VALUES ('" . addslashes($subscription_uri) . "',NOW())");
		if ( is_trace_on() ) 
			$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received feedback offer','','".addslashes($subscription_uri)."','Success',NOW())");
		return 1;
	}

	function feedback_request($args)
	{
		global $wpdb;
		global $wpmuBaseTablePrefix;    
		
		if (count($args) < 3)
			return new IXR_Error(201, "Invalid number of arguments for feedback.request");

		$target = $args[0]; 	// the requested feed..
		$uri = $args[1]; 	// the requesting xmlrpc uri
		$token = $args[2]; 	// the token for trusting our updates

		// XXX: Verify that xmlrpc uri is valid
		// XXX: Verify that requested feed exists and is valid/public

		// In case of WordPress Multi-User we need to find the correct table prefix
		

		$wpdb->hide_errors();
		
		$alreadyhave = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."sub_requests WHERE uri='".$uri."'");
		if (!$alreadyhave ) 
		{
			$wpdb->query("INSERT INTO ".$wpdb->prefix."sub_requests (token,uri,description,send_time) VALUES ('" . addslashes($token) . "','" . addslashes($uri) . "','',NOW())");
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received feedback request','','".addslashes($uri)."','Success',NOW())");
		}
		else
		{ 
			$wpdb->query("UPDATE ".$wpdb->prefix."sub_requests SET token = '" . addslashes($token) . "', send_time = NOW() WHERE uri='".$uri."'");
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received feedback request','','".addslashes($uri)."','Notice:Already have,token updated!',NOW())");
		}

		return 1;
	}

	function feedback_notify($args)
	{
		 include_once (ABSPATH . '/wp-content/plugins/feedback/rss.php'); 
		set_full_uri();
		global $wpdb;
		global $wpmuBaseTablePrefix;  
	
		$resp =0;

		$data = $args[0]; // data passed, should be URL to feed/xml-changeset (atom-pp?)
		$token = $args[1]; // token identifying and authorizing update notification

		$subscription = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."sub_feeds WHERE token='" . addslashes($token) . "'");
		if ($subscription)
		{
			// XXX: possible security check if $data and $subscription->feed_uri are similar?
			// fetch the feed, and update database!
			$resp =1;
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received notification','".addslashes($subscription->channel_name)."','".addslashes($subscription->channel_link)."','Success',NOW())");
			
			$rss = fetch_rss($subscription->feed_uri);
			

			// ATT. Currently deletes old feed entries, and only reflects latest 30 entries or so
			// XXX: Either switch to Atom-PP style execution sets, OR: go through feed, check for
			//	changes in fields (using $i["link"] as key) or if items are missing compared
			//	to already retrieved entries and detect deletions.
			$wpdb->hide_errors();
			$deletetags= "DELETE ".$wpdb->prefix."sub_feed_tags.* FROM ".$wpdb->prefix."sub_feed_tags, ".$wpdb->prefix."sub_feed_items, ".$wpdb->prefix."sub_feeds". 
					" WHERE ".$wpdb->prefix."sub_feed_tags.item_id = ".$wpdb->prefix."sub_feed_items.id AND ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id". 
					" AND ".$wpdb->prefix."sub_feeds.id=".$subscription->id."";
			$wpdb->query($deletetags);
			$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_feed_items WHERE feed_id=".$subscription->id);

			while ($i = array_shift($rss->items))
			{
				$wpdb->query("INSERT INTO ".$wpdb->prefix."sub_feed_items  (feed_id,
					item_uri,
					item_hash,
					title,
					content,
					pubdate)
				VALUES (
					'" . $subscription->id . "',
					'" . addslashes($i["link"]) . "',
					'" . md5($i["link"]) . "',
					'" . addslashes($i["title"]) . "',
					'" . addslashes($i["content"]["encoded"]) . "',
					FROM_UNIXTIME('" . strtotime($i["pubdate"]) . "')
				)");
			$lookup = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."sub_feed_items ORDER BY Id DESC LIMIT 0,1");	
			foreach ( $i["category"] as $cat )
				$wpdb->query("INSERT INTO ".$wpdb->prefix."sub_feed_tags values(".$lookup->id.",'".addslashes($cat)."')");
			}
		}else
		{
			if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ".$wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes(get_user_nickname($wpdb->blogid))."','received notification','','".addslashes($data)."','Rejected:No subscription found!',NOW())");
			return new IXR_Error(104, "No Subscription found!");
		 }
		return 1;
	}
}

$fb_xmlrpc_server = new fb_xmlrpc_server();

?>
