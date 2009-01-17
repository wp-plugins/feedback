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

function good_parse_url($url)
{
	$url = trim($url);
	$parsed = array();
	$parts = split(":", $url);
	if ($parts[0] != 'http')
		return undef;

	$rest = substr($url, 7);
	$parts = split("/", $rest);
	$hostport = array_shift($parts);
	if (strpos($hostport, ":") !== FALSE)
	{
		$hp = split(":", $hostport);
		$parsed["host"] = $hp[0];
		$parsed["port"] = $hp[1];
	}
	else
	{
		$parsed["host"] = $hostport;
		$parsed["port"] = 80;
	}

	$rest = "/".join("/", $parts);
	if(strpos($rest, "?") !== FALSE)
	{	$pv = split("?", $rest);
		$parsed["path"] = $pv[0];
		$parsed["params"] = $pv[1];
	}
	else
	{
		$parsed["path"] = $rest;
	}

	return $parsed;
}

function get_feedback_uri($blog_uri)
{
	include_once (ABSPATH . '/wp-content/plugins/feedback/rss.php'); 
	$feed = _fetch_remote_file($blog_uri);
	$rpc_feedback_uri = "";
	if($feed->headers)
	{
		while ($header = array_shift($feed->headers))
		{
			$hkvp = split(": ", $header);
			if ($hkvp[0] == 'X-Feedback')
			{
				$rpc_feedback_uri = $hkvp[1];
				break;
			}
		}
	}

	if (!$rpc_feedback_uri)
	{
		// Can't count on simplexml being installed, will preg hax
		$matches = array();
		if(preg_match("/<link rel=\"feedback\".*?href=\"(\S+)\".*?>/", $feed->results, $matches))
		{
			// Yes there was a feedback URI!
			$rpc_feedback_uri = preg_replace("/.*?href=\"(\S+)\".*/", '$1', $matches[0]);
		}
	}

	return $rpc_feedback_uri;
}

function get_feed_uri($uri)
{
	// first we determine if this is actually a feed.. so lets try and have Magpie parse it
	$feed_uri = "";
	$rss = fetch_rss($uri);
	if($rss->feed_type)
	{
		// Identified as some type of feed
		return $uri;
	}

	// not a rss feed... time to fetch and parse for link rel
	// <link rel="alternate" type="application/rss+xml" title="x" href="http://localhost/frido/?feed=rss2" />
	// more ways of advertising a rss feed within page..?
	$page = _fetch_remote_file($uri);
	$matches = array();
	if (preg_match('/<link.*?type="application\/rss\+xml".*>/', $page->results, $matches))
	{
		// And the alternative version *is* an rss feed... apparently?? lets verify
		$feed_uri = preg_replace("/.*?href=\"(\S+)\".*/", '$1', $matches[0]);
		$rss = fetch_rss($feed_uri);
		if($rss->feed_type)
		{
			// It was identified!
			return $feed_uri;
		}
	}
	return 0; // If we ended up here, there was no feed or nuthin :-(
}

function feedback_notify($uri, $token)
{
	include_once(ABSPATH . WPINC . '/class-IXR.php');
	error_log("feedback_notify (client):");
	error_log("feedback_notify (client): uri=" . $uri . " token=" . $token);

	$urlparts = good_parse_url($uri);
	error_log("feedback_notify (client): urlparts=" . vardump($urlparts));
	$client = new IXR_Client($urlparts["host"], $urlparts["path"], $urlparts["port"], 5);
	error_log("feedback_notify (client): client=" . vardump($client));
	$resp = $client->query('feedback.notify', get_bloginfo('rss2_url'), $token);
	error_log("feedback_notify (client): client (POST QUERY)=" . vardump($client));
	error_log("feedback_notify (client): resp=" . vardump($resp));
return $resp;
}

function vardump($v)
{
	ob_start();
	eval(var_dump($v));
	$result = ob_get_contents();
	ob_end_clean();
	return $result;
}

function do_feedbacks($post_id) // $post_id not used at the moment!
{
	global $wpdb;
	global $wpmuBaseTablePrefix;
	global $current_user;

	$post = get_post($post_id);

	error_log("do_feedbacks:");
	$feedbacks = $wpdb->get_results("SELECT id,token,uri,description FROM ".$wpdb->prefix."sub_requests");
	error_log("do_feedbacks: " . vardump($feedbacks));
	if(is_array($feedbacks)){
		foreach ( $feedbacks as $feedback )		{
				$resp = feedback_notify($feedback->uri, $feedback->token);
				if($resp == 1)
				{
					$wpdb->query("UPDATE ".$wpdb->prefix."sub_requests SET Success = Success+1, last_success_time=NOW() WHERE id='".$feedback->id."'");
					if(is_trace_on() ) 
						$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent notification for post ".addslashes($post->post_title)."','".addslashes($feedback->description)."','".addslashes($feedback->uri)."','Success',NOW())");
				}
				else
				{
					$wpdb->query("UPDATE ".$wpdb->prefix."sub_requests SET Failure = Failure+1 WHERE id='".$feedback->id."'");
					if(is_trace_on()) 
						$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent notification for post ".addslashes($post->post_title)."','".addslashes($feedback->description)."','".addslashes($feedback->uri)."','Failure',NOW())");
				}
			}
	}
}

function send_fb_header()
{
	$options = get_option('feedback_options');

	if (!$options)
	{
		$options = feedback_get_default_options();
	}
	
	header('X-Feedback: '. $options['feedback_uri']);
}

function set_full_uri()
{
	$_SERVER['FULL_URI'] = 'http';
	$script_name = '';
	if (isset($_SERVER['REQUEST_URI']))
		$script_name = $_SERVER['REQUEST_URI'];
	else
	{
		$script_name = $_SERVER['PHP_SELF'];
		if($_SERVER['QUERY_STRING']>' ')
			$script_name .=  '?'.$_SERVER['QUERY_STRING'];
	}

	if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on')
		$_SERVER['FULL_URI'] .=  's';

	$_SERVER['FULL_URI'] .=  '://';

	if($_SERVER['SERVER_PORT']!='80')
	{
		$_SERVER['FULL_URI'] .=
		$_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'].$script_name;
	}
	else
		$_SERVER['FULL_URI'] .=  $_SERVER['HTTP_HOST'].$script_name;
}

function get_user_nickname( $id )
{
	global $wpdb;
	
	if(!$id)
		$id=1;
	$blog=$wpdb->get_row("SELECT path FROM $wpdb->blogs WHERE blog_id=".$id."");
	return $blog->path;
	
}

// Check if trace mechanizm activated by a particular user
function is_trace_on()
{
	global $wpmuBaseTablePrefix;
	global $wpdb;
	$options = get_option('feedback_options');

	if (!$options)
	{
		$options = feedback_get_default_options();
	}
	
	$table = $wpmuBaseTablePrefix."feed_stats";

	if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $options["feedback_trace_enable"]== "No")
	{		
		return 0;
	}
	
	return 1;
}

// Check if trace mechanizm activated by admin
function is_trace_active()
{
	global $wpmuBaseTablePrefix;
	global $wpdb;
		
	$table = $wpmuBaseTablePrefix."feed_stats";

	if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table )
	{		
		return 0;
	}
	
	return 1;
}

?>
