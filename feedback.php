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

/*
Plugin Name: FeedBack
Plugin URI: http://www.icamp.eu/watchwork/interoperability/collaboration-networks
Description: FeedBack is a standard inspired by PingBack, and designed to establish relatively secure push mechanisms for feed sharing and management.
Version: 1.0
Author: Steinn E. Sigurdarson, Ahmet Soylu, Fridolin Wild
Author URI: http://www.icamp.eu/learnmore/project/people
*/
require("lib.php");
set_full_uri();

// Plugin tables
function fb_tables()
{
	return array(
		array("name"=>"sub_offers",
			"spec"=>"id int(4) unsigned not null auto_increment primary key,
				feed_uri text not null,
				receive_time timestamp not null"
		),
		array("name"=>"sub_requests",
			"spec"=>"id int(4) unsigned not null auto_increment primary key,
				token text not null,
				uri text not null,
				description text not null,
					send_time timestamp not null,
					last_success_time timestamp not null, 
					success bigint(20) not null default '0',
					failure bigint(20) not null default '0'"
		),
		array("name"=>"sub_feeds",
			"spec"=>"id int(4) unsigned not null auto_increment primary key,
				feed_uri text not null,
				last_update timestamp not null,
				intervalfetch bit not null default 0,
				channel_name text not null,
				channel_link text not null,
				token varchar(64) not null unique,
					blogroll bigint(11) not null default '0'"
		),
		array(  "name"=>"sub_feed_items",
			"spec"=>"id int(4) unsigned not null auto_increment primary key,
				feed_id int(4) unsigned not null,
				item_uri varchar(512) not null,
				item_hash varchar(32) not null unique,
				title text not null,
				content text not null,
				pubdate timestamp not null"
		),
			  array("name"=>"sub_feed_tags",
				"spec"=>"item_id int(4) unsigned not null,
				feed_tag text not null"
		),

			array("name"=>"feed_stats",
			"spec"=>"id int(4) unsigned not null auto_increment primary key,
				 feed_local_user text not null,  
				 feed_action varchar(50) not null,
				feed_remote_channel text,
				 feed_remote_channel_uri text,
				 description text,
				 time timestamp not null"
		)
	);
}


//Default Reading options 
function feedback_get_default_options() {
	$options = array();
	$options['feedback_post_number'] = 5;
	$options['feedback_line_number'] = 5;	
	$options['feedback_trace_limit'] = 50;
	$options['feedback_trace_enable'] = "Yes";
	$options['feedback_uri'] = get_bloginfo('siteurl')."/wp-content/plugins/feedback/xmlrpc.php";
	return $options;
}



// INSTALL / REMOVE
function fb_install()
{
	global $wpdb;
	global $wpmuBaseTablePrefix;
	global $current_user;

	$tables = fb_tables();
	require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
	foreach ($tables as $table)
	{
		if( $table["name"] != "feed_stats" )
			$table_name = $wpdb->prefix . $table["name"];
		else
			$table_name = $wpmuBaseTablePrefix . $table["name"];

		// Make sure tables don't exists!
		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name)
		{
			$sql = "CREATE TABLE " . $table_name . "(\n";
			$sql .= $table["spec"];
			$sql .= "\n);\n";
			dbDelta($sql);
		}
	}
	
	// Set the feedback_uri option name
	add_option('feedback_options', feedback_get_default_options(), 'Options for the Feedback plugin');
	if ( is_trace_on() )
		$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','activated pugin','','','Success',NOW())");
}

//Remove feedback plugin
function fb_remove()
{
	global $wpdb;
	global $wpmuBaseTablePrefix;
	global $current_user;
	$tables = fb_tables();
	foreach ($tables as $table)
	{
		if( $table["name"] == "feed_stats" )
			$table_name = $wpmuBaseTablePrefix . $table["name"];
		else			
			$table_name = $wpdb->prefix . $table["name"];               
		
		if( $table["name"] != "feed_stats" )
			$wpdb->query("DROP TABLE " . $table_name);
		else if ( $current_user->nickname == 'admin' ) 
			$wpdb->query("DROP TABLE " . $table_name);
	}

	delete_option('feedback_options');
	remove_action('publish_post', 'do_feedbacks');
	remove_action('admin_menu', 'fb_menu');
	remove_action('init', 'send_fb_header');
	if ( is_trace_on() )
		$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','deactivated plugin','','','Success',NOW())");
}	

// Adds the plugin menu
function fb_menu()
{
	global $wpdb;
	global $current_user;
	
	$offer = $wpdb->get_results("SELECT count(*) AS sum FROM ".$wpdb->prefix."sub_offers");
	
	add_menu_page('Read', 'Read', 8, "feedback/feedback.php", 'fb_display_feeds');    
	add_submenu_page("feedback/feedback.php", 'My Subscriptions', 'My Subscriptions ('.$offer[0]->sum.")", 8, 'pendingoffers', 'fb_display_offers');
	add_submenu_page("feedback/feedback.php", 'My Readers', 'My Readers', 8, 'subscriptions', 'fb_main');
	 if( $current_user->nickname == 'admin' AND is_trace_active() )
	 {
		 add_submenu_page("feedback/feedback.php", 'Trace', 'Trace', 8, 'trace', 'fb_display_trace');
	 }

}

function save_feed_tokenize($feed_uri, $db)
{
	global $wpdb;
	// Parse the entries
	$rss = fetch_rss($feed_uri);
	// XXX: error handling, broken feed_uri ? not feed? not recognized?
	if ($rss) // now we assume everything is ok with the feed
	{
		$token = md5($offer->feed_uri . time() . get_option('siteurl'));
		$db->query("INSERT INTO ".$wpdb->prefix."sub_feeds (feed_uri,channel_name,channel_link,token) VALUES ('" .addslashes($feed_uri). "','" . addslashes($rss->channel["title"]) . "','" . addslashes($rss->channel["link"]) . "','" . addslashes($token) . "')");
		$r = $db->get_row("SELECT LAST_INSERT_ID() as feed_id");
		$feed_id = $r->feed_id;
		while ($i = array_shift($rss->items))
		{
			$db->query("INSERT INTO ".$wpdb->prefix."sub_feed_items
					(feed_id,
					item_uri,
					item_hash,
					title,
					content,
					pubdate)
				VALUES (
					'" . addslashes($feed_id) . "',
					'" . addslashes($i["link"]) . "',
					'" . md5($i["link"]) . "',
					'" . addslashes($i["title"]) . "',
					'" . addslashes($i["content"]["encoded"]) . "',
					FROM_UNIXTIME('" . strtotime($i["pubdate"]) . "')
					)");
			$lookup = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."sub_feed_items ORDER BY Id DESC LIMIT 0,1");	
			foreach( $i["category"] as $cat )
				$wpdb->query("INSERT INTO ".$wpdb->prefix."sub_feed_tags values(".addslashes($lookup->id).",'".addslashes($cat)."')");
		}
		return $token;
	}
	return 0;
}

//Display first 50 trace  record
function fb_display_trace()
{
	global $wpdb;
	global $wpmuBaseTablePrefix;
	$options = get_option('feedback_options');
	
	if (!$options)
	{
		$options = feedback_get_default_options();
	}
	
	$stats->items = $wpdb->get_results("SELECT * FROM ". $wpmuBaseTablePrefix."feed_stats ORDER BY time DESC LIMIT 0,50");
	
	
	echo "<div class='wrap'>";
	echo "<h2>Feedback Trace</h2>";
	echo "<p>Only last ".$options['feedback_trace_limit']." trace records has been displayed!</p>";
	echo "<table class='widefat'>";
	echo "<thead>";
	echo "<tr>";
	echo "<td>#</td>";
	echo "<td>User / Blog</td>";
	echo "<td>Action</td>";
	echo "<td>Remote Channel</td>";
	echo "<td>Remote Channel Link</td>";
	echo "<td>Description</td>";
	echo "<td>Time</td>";
	echo "</tr>";
	echo "</thead>";	

	$i=1;
	
	while ($stat = array_shift($stats->items))
	{	
		$class="";
		if ( $i % 2 == 0 )
			$class = 'alternate';
		echo "<tr class='".$class."'>";
		echo "<td>".$i."</td>";
		echo "<td>".$stat->feed_local_user."</td>";
		echo "<td>".$stat->feed_action."</td>";
		echo "<td>".$stat->feed_remote_channel."</td>";
		echo "<td>".$stat->feed_remote_channel_uri."</td>";
		echo "<td>".$stat->description."</td>";
		echo "<td>".$stat->time."</td>";
		echo "</tr>";
		$i++;
	}
	
	echo "</table>";
	echo "</div>";
}

// Main plugin control
function fb_main()
{
	global $wpdb;
	global $wpmuBaseTablePrefix;
	global $current_user;
	define(MAGPIE_CACHE_ON, 0);

	include_once (ABSPATH . WPINC . '/class-IXR.php');
	include_once (ABSPATH . '/wp-content/plugins/feedback/rss.php');

	print "<!--\n";
	print_r($_SERVER);
	print "-->\n";
	$url = split("&", $_SERVER["REQUEST_URI"] );
	?>
	<script>


	var type = "IE";	

	BrowserSniffer();

	function BrowserSniffer()
	{
		if (navigator.userAgent.indexOf("Opera")!=-1 && document.getElementById) type="OP";
		else if (document.all) type="IE";
		else if (document.layers) type="NN";
		else if (!document.all && document.getElementById) type="MO";
		else type = "IE";		
	}



	function ChangeContent(id, str) {
		
		if (type=="IE") {
			document.all[id].innerHTML = str;
		}
		if (type=="NN") { 
			document.layers[id].document.open();
			document.layers[id].document.write(str);
			document.layers[id].document.close();
		}
		if (type=="MO" || type=="OP") {		
		document.getElementById(id).innerHTML = str;
		}
	}

	function GetContent(id) {
		var content;
		if (type=="IE") {
			content = document.all[id].innerHTML;
		}
		if (type=="NN") { 
			document.layers[id].document.open();
			document.layers[id].document.write(str);
			document.layers[id].document.close();
		}
		if (type=="MO" || type=="OP") {		
			content = document.getElementById(id).innerHTML;
		}
		
		return content;
	}

		
	function on_off_description(act,row)
	{	var indx;
		var str;
		var savestr;
		
		if (act == "edit")
		{	
			indx = "cont"+row;				
			str = "<input type='text' name='desc' id='desc' value='"+ GetContent(indx) +"'>";
			ChangeContent(indx,str);
			indx = "op"+row;
			str = "<a href='#' onclick='on_off_description(\"save\","+row+")'>Save</a>";
			ChangeContent(indx,str);			
		}
		else
		{
			indx = "cont"+row;
			str =  document.getElementById("desc").value;
			savestr = document.getElementById("desc").value;
			ChangeContent(indx,str);			
			indx = "op"+row;
			str = "<a href='#' onclick='on_off_description(\"edit\","+row+")'>Edit</a>";
			ChangeContent(indx,str);	
			ajax_save(row,savestr);		
		}
		
	}


	function ajax_save(channel_id,desc)
 	{
		var xmlHttp;
		var parameters;
		try
		{
		// Firefox, Opera 8.0+, Safari
		xmlHttp=new XMLHttpRequest();
		}
 
catch (e)
		{
		// Internet Explorer
		try
		{
			xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
		}
		catch (e)
		{
			try
		{
				xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
		}
		catch (e)
		{
			alert("Your browser does not support AJAX!");
			return false;
		}
		}
		}
	xmlHttp.onreadystatechange=function()
	{
		if(xmlHttp.readyState==4)
			{
			// document.myForm.time.value=xmlHttp.responseText;
				//alert(xmlHttp.responseText);
			}
		}
    		
	//xmlHttp.open("GET",'<?php echo $url[0] ."&act=updatedesc&id=" ?>'+channel_id,true);
	//xmlHttp.send(null);
	parameters = "description="+encodeURI(desc);
	xmlHttp.open('POST', '<?php echo $url[0] ."&act=updatedesc&id=" ?>'+channel_id, true);
	xmlHttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	xmlHttp.setRequestHeader("Content-length", parameters.length);
	xmlHttp.setRequestHeader("Connection", "close");
	xmlHttp.send(parameters);
}
	</script>
<style type="text/css">
	.feed
	{
		width: 250px;
		float: left;
		border: 2px solid #448ABD;
		background-color: #83B4D8;
		margin-right: 8px;
	}

	.feed-title
	{
		background-color: #14568A;
		color: #fff;
		padding: 2px;
	}
	.clearfix { zoom: 1; }

	.clearfix:after { content: "."; display: block; height: 0; clear: both; visibility: hidden; }
</style>
<?php

	$error = "";
	$message = "";
	$act = "";
	

	if ($_POST["act"])
		$act = $_POST["act"];
	else
		$act = $_GET["act"];

	switch($act)
	{
		case 'offer':
			$target = $_POST["target"] ? $_POST["target"] : $_GET["target"];
			if (!$target)
			{
				$error = "No target blog URI given";
				if ( is_trace_on() )
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent subscription offer','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())");
				break;
			}
			$rpc_feedback_uri = get_feedback_uri($target);

			if (!$rpc_feedback_uri)
			{
				$error = "No FeedBack URI found at site: " . $target;
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent subscription offer','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())");
				break;
			}
			$urlparts = good_parse_url($rpc_feedback_uri);
			$client = new IXR_Client($urlparts["host"], $urlparts["path"], $urlparts["port"], 3);
			$client->useragent .= ' -- WordPress/' . $wp_version;
			$resp = $client->query('feedback.offer', get_bloginfo('rss2_url'), $target);
			if (!$resp)
			{
				$error = "Error: " . $client->error->code . " (" . $client->error->message . ")";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent subscription offer','','".addslashes($target)."','Failure:". addslashes($client->error->code) . " (" . addslashes($client->error->message) . ")',NOW())");
			}
			else
			{
				$message = "FeedBack notification and subscription offer sent to: " . $target;
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent subscription offer','','".addslashes($target)."','Success',NOW())");
			}
			break;
		case 'advertise':			
			$ablog = $_POST["ablog"];
			$tblog = $_POST["tblog"];
			
			if(!$ablog)
			{
				$error = "No Blog URI given to be advertised";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for '".$ablog. "','','".addslashes($tblog)."','Failure:".addslashes($error)."',NOW())");
				break;
			}
			
			if(!$tblog)
			{
				$error = "No target Blog URI given";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Failure:".addslashes($error)."',NOW())");
				break;
			}		
			
			$arpc_feedback_uri = get_feedback_uri($ablog);
			$trpc_feedback_uri = get_feedback_uri($tblog);
			
			if (!$arpc_feedback_uri)
			{
				$error = "No FeedBack URI found at site: " . $ablog;
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Failure:".addslashes($error)."',NOW())");
				break;
			}
			
			if(!$trpc_feedback_uri)
			{
				$error = "No FeedBack URI found at site: " . $tblog;
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Failure:".addslashes($error)."',NOW())");
				break;
			}
			
			$feed_uri = get_feed_uri($ablog);
			
			if(!$feed_uri)
			{
				$error = "No feed URI found at site: " . $ablog . " for subscriptions.";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}
			
			$urlparts = good_parse_url($trpc_feedback_uri);
			$client = new IXR_Client($urlparts["host"], $urlparts["path"], $urlparts["port"], 3);
			$client->useragent .= ' -- WordPress/' . $wp_version;
			$resp = $client->query('feedback.offer', $feed_uri, $tblog);	
			
			if(!$resp)
			{
				$error = "Error: " . $client->error->code . " (" . $client->error->message . ")";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Failure:". addslashes($client->error->code) . " (" . addslashes($client->error->message) . ")',NOW())");
				break;
			}
			else
			{
				$message = $ablog." advertised to " . $tblog;
				if ( is_trace_on() )
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','sent advertisement for ".addslashes($ablog)."','','".addslashes($tblog)."','Success',NOW())");
			}			
			break;
		case 'deletesubscriber':     
			$d_uri = $wpdb->get_row("SELECT uri,description FROM ".$wpdb->prefix."sub_requests WHERE id = '" . addslashes($_GET["id"]) . "'");
			//echo "SELECT uri ".$wpdb->prefix."sub_requests WHERE id = '" . addslashes($_GET["id"]) . "'";                   
			$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_requests WHERE id = '" . addslashes($_GET["id"]) . "'");
			if(is_trace_on())
			     $wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','deleted subscriber','".addslashes($d_uri->description)."','".addslashes($d_uri->uri)."','Success',NOW())");
			
			if($d_uri->description == "")
				$description = $d_uri->uri;
			else
				$description = $d_uri->description;
				
			$message = "Subscribtion of ". $description ." cancelled!";
			break;  
		case 'updatedesc':
			$d_urib = $wpdb->get_row("SELECT description FROM ".$wpdb->prefix."sub_requests WHERE id = '" . addslashes($_GET["id"]) . "'");
			$wpdb->query("UPDATE ".$wpdb->prefix."sub_requests SET description='".$_POST["description"] ."' WHERE id = '" . $_GET["id"] . "'");                      
			$d_uri = $wpdb->get_row("SELECT uri,description FROM ".$wpdb->prefix."sub_requests WHERE id = '" . addslashes($_GET["id"]) . "'");
			if (is_trace_on())
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','changed description','".addslashes($d_urib->description)."','".addslashes($d_uri->uri)."','to ".addslashes($d_uri->description)."',NOW())");
			break;
		default:
			break;
	}
	// Get pending offers
	$subscribers = $wpdb->get_results("SELECT id, send_time, success, failure, last_success_time, description, uri  FROM ".$wpdb->prefix."sub_requests");
?>
	<div class="wrap">
<?php

	if ($error)
	{
?>
		<div class="error">
			<?php echo $error; ?>
		</div>
<?php
	}
	if ($message)
	{
?>
		<div id="message" class="updated fade">
			<?php echo $message; ?>
		</div>
<?php
	}
?>
	<div class="wrap">
		<h2>Offer subscription</h2>
		<form method="post" action="<?php echo $_SERVER["REQUEST_URI"] ?>">
			<input type="hidden" name="act" value="offer" />
			Target blog URI: <input type="text" name="target" /> <input type="submit" value="Send offer"  class="button button-highlighted"/>
		</form>
		<strong>NEW!</strong>
		<a href="javascript:document.location='<?php echo $_SERVER["FULL_URI"] ?>&act=offer&target='+escape(window.location);">FeedIt!</a>
	</div>
	
	<br/><br/>
	
	<div class="wrap">
		<h2>Advertise blog</h2>
		<form method="post" action="<?php echo $_SERVER["REQUEST_URI"] ?>">
			<input type="hidden" name="act" value="advertise"  class="button button-highlighted"/>
			Blog URI:&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp; <input type="text" name="ablog" /><br /> Target blog URI: <input type="text" name="tblog" />  <input type="submit" value="Advertise" />
		</form>	
	</div>
	
	<br/><br/>
	
<?php

?>
	<div class="wrap">
		<h2>My readers</h2>
<?php
	if ($subscribers)
	{
?>  
		<div>
			<table class="widefat">
				<thead>
					<tr>	 
						<td>#</td> 
						<td>Edit</td>
						<td>Description</td>
						<td>Subscribed Since</td>
						<td># Success</td>
						<td># Failure</td>
						<td>Last Success Time</td>
						<td>Action</td>
					</tr>
				</thead>
<?php
	$i=1;
	while ($row = array_shift($subscribers))
	{
		$class='';   
		if($i % 2 == 0)
			$class='alternate'; 
		if($row->description == "")
			$description = $row->uri;
		else
			$description = $row->description;
		
			print "<tr class='".$class."'>";
			print "<td>".$i."</td>";
			print "<td><div id='op".$row->id."'><a href='#' onclick='on_off_description(\"edit\",".$row->id.")'>Edit</a></div></td>";
			print "<td><div id='cont".$row->id."'>" .$description . "</div></td><td>" . $row->send_time . "</td>";
			print "<td align='center'>" .$row->success . "</td><td align='center'>" . $row->failure . "</td>";
			print "<td align='center'>" .$row->last_success_time . "</td><td align='center'><a href='".$_SERVER["REQUEST_URI"]."&act=deletesubscriber&id=" . $row->id . "'>Cancel</a></td></tr>";
			$i++;
	
	}
?>
			</table>
		</div>

<?php
	} else {
		echo "No subscribers!";
	}
	echo "</div>";

}

function fb_display_feeds()
{
	global $wpdb;
	global $current_user;
	global $wpmuBaseTablePrefix;   
 
	$error = "";
	$message = "";
	$act = "";
	
	$options = get_option('feedback_options');

	if (!$options)
	{
		$options = feedback_get_default_options();
	}

	$chunk=$options["feedback_post_number"];
	$lines=$options["feedback_line_number"];
	

	if($_POST["act"])
		$act = $_POST["act"];
	else
		$act = $_GET["act"];

	if($_GET["pagenum"] =="")
		$pagenum_r = 1;
	else
		$pagenum_r = $_GET["pagenum"];

	 $offer = $wpdb->get_results("SELECT count(*) AS sum FROM ".$wpdb->prefix."sub_offers");
	 $channelsq = "SELECT channel_name, ".$wpdb->prefix."sub_feeds.id,count(*) AS Sum FROM ".$wpdb->prefix."sub_feed_items, ".$wpdb->prefix."sub_feeds WHERE ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id GROUP BY ".$wpdb->prefix."sub_feeds.channel_name, ".$wpdb->prefix."sub_feeds.id ORDER BY channel_name";
	 $tagsq = "SELECT feed_tag, COUNT(*) as numitems FROM ".$wpdb->prefix."sub_feed_tags GROUP BY feed_tag ORDER BY feed_tag";
	
	switch($act)
	{
						
	case 'reply':				
				class fb_replier
				{
					var $post_content;
					var $post_title;
					var $post_status;
				}

				$reply_detail=$wpdb->get_results("SELECT item_uri, channel_name, title, channel_link FROM ".$wpdb->prefix."sub_feed_items,".$wpdb->prefix."sub_feeds WHERE ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id and ".$wpdb->prefix."sub_feed_items.id=".$_GET["id"]);
				$fb_reply = new fb_replier();
								 
				$fb_reply->post_title = "Re:".$reply_detail[0]->title;
				$fb_reply->post_content = "I was reading <a href=\"".$reply_detail[0]->item_uri."\">".$reply_detail[0]->title."</a> on <a href=\"".$reply_detail[0]->channel_link."\">".$reply_detail[0]->channel_name."</a> and";
				$fb_reply->post_status = 'draft';
 				$fb_reply->post_author = $current_user->ID;
				if ( is_trace_on() ) 
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','replied','".addslashes($reply_detail[0]->channel_name)."','".addslashes($reply_detail[0]->channel_link)."','on post <a href=".addslashes($reply_detail[0]->item_uri).">".addslashes($reply_detail[0]->title)."</a>',NOW())");							
				$postnumber= wp_insert_post($fb_reply);
				echo "<script>window.location=\"post.php?action=edit&post=$postnumber\"</script>";
				break;
	case 'showpost':
				$feedsq= "SELECT ".$wpdb->prefix."sub_feed_items.id, feed_id, item_uri, title, content, pubdate,".$wpdb->prefix."sub_feeds.channel_name, 1 AS Sum FROM "
					.$wpdb->prefix."sub_feed_items, ".$wpdb->prefix."sub_feeds  WHERE  ".$wpdb->prefix."sub_feeds.id = ".$wpdb->prefix."sub_feed_items.feed_id AND  ".$wpdb->prefix.
					 "sub_feed_items.id=".addslashes($_GET["sid"]);
				
				break;
	case 'save':
				$newoptions = array();
				$newoptions['feedback_post_number'] = intval($_POST["chunk"]);
				$newoptions['feedback_line_number'] = intval($_POST["lines"]);
				$newoptions['feedback_uri'] = $options['feedback_uri'];
				$newoptions['feedback_trace_enable'] = $_POST["prv"];
				update_option('feedback_options', $newoptions);
				
				$options = get_option('feedback_options');

				$chunk = $options['feedback_post_number'];
				$lines = $options['feedback_line_number'];
				
	default:
			 	$limits= (($pagenum_r-1 ) * $chunk);
	 		 	$feedsq="";
  	 		 	$selectsq = "SELECT DISTINCT ".$wpdb->prefix."sub_feed_items.id, feed_id, item_uri, title, content, pubdate,".$wpdb->prefix."sub_feeds.channel_name";
			 	$fromwheresq ="FROM ".$wpdb->prefix."sub_feed_items,".$wpdb->prefix."sub_feeds,".$wpdb->prefix."sub_feed_tags WHERE ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id AND ".$wpdb->prefix."sub_feed_items.id = ".$wpdb->prefix."sub_feed_tags.item_id";
			 	$order=" ORDER BY pubdate DESC LIMIT ".$limits.",".$chunk."";
			 	$countsq ="SELECT count(*) as Sum FROM ".$wpdb->prefix."sub_feed_items,".$wpdb->prefix."sub_feeds, ".$wpdb->prefix."sub_feed_tags WHERE ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id AND ".$wpdb->prefix."sub_feed_items.id = ".$wpdb->prefix."sub_feed_tags.item_id";        

			 	if(addslashes($_GET["id"]) != "")
			 	{
					 $fromwheresq = $fromwheresq." AND ".$wpdb->prefix."sub_feed_items.feed_id =".addslashes($_GET["id"]);
					$countsq= $countsq." AND ".$wpdb->prefix."sub_feed_items.feed_id =".addslashes($_GET["id"]);
			 	}
			 	if(addslashes($_GET["tid"]) != "")
				{
					 $fromwheresq = $fromwheresq." AND ".$wpdb->prefix."sub_feed_tags.feed_tag ='".addslashes($_GET["tid"])."'";	
					$countsq= $countsq." AND ".$wpdb->prefix."sub_feed_tags.feed_tag ='".addslashes($_GET["tid"])."'";	
			 	}
			 	$feedsq= $selectsq.",(".$countsq.") AS Sum ".$fromwheresq.$order;
				
				break;
        }

 ?>
  <style type="text/css">
	.feed
	{
		width: 250px;
		float: left;
		border: 2px solid #448ABD;
		background-color: #83B4D8;
		margin-right: 8px;
	}
		

	.feed-title
	{
		background-color: #14568A;
		color: #fff;
		padding: 2px;
	}
	.clearfix { zoom: 1; }

	.clearfix:after { content: "."; display: block; height: 0; clear: both; visibility: hidden; }
	</style>
<?php
	$channels->items = $wpdb->get_results($channelsq);
	$tags->items = $wpdb->get_results($tagsq);         
	$feed->items = $wpdb->get_results($feedsq);
	//echo $feedsq;
	$url = split("&", $_SERVER["REQUEST_URI"] );

	if($offer[0]->sum >= 1)
	{
?>
	<div id="message" class="updated fade">
		<?php  echo "You have ".$offer[0]->sum." pending offers!"; ?>
	</div>
<?php
	}

?>
	<div class="wrap">		
		<div id="submitbox" class="submitbox">
					
					<!-- Channels pane -->
					<h3 class="dbx-handle">Channels</h3>
					<ul>
						<?php	
						$allchannels = $wpdb->get_row("SELECT count(*) AS Sum FROM " .$wpdb->prefix."sub_feed_items");
						echo "<li>";
						if (addslashes($_GET["id"]) != "" OR $_GET["sid"] != "" )	
							echo "<a href='". $url[0] ."&act=display&id=&tid=" .addslashes($_GET["tid"]). "&pagenum=1'>";
						echo "All Channels";
						if(addslashes($_GET["id"]) != "" )
							echo " </a>";
						echo "</li>";
						echo " (".$allchannels->Sum.")";
						while ($channel = array_shift($channels->items))
						{
							echo "<li>";
							if($channel->id != addslashes($_GET["id"]))	
							echo "<a href='". $url[0] ."&act=display&id=" . $channel->id . "&tid=".addslashes($_GET["tid"])."&pagenum=1'>";
							echo $channel->channel_name ;
							if($channel->id != addslashes($_GET["id"]))
								echo " </a>";
							echo "</li>";
							echo " (".$channel->Sum.")";
						
						}
						?>
					</ul>				
				

					<!-- Tag cloud pane -->
					<h3 class="dbx-handle">Tag Cloud</h3>
					<ul>
						<?php	
						$alltags = $wpdb->get_row("SELECT count(*) AS Sum FROM " .$wpdb->prefix."sub_feed_tags");
						
						echo "<li>";
						if (addslashes($_GET["tid"]) != "" OR $_GET["sid"] != "" )
							echo "<a href='". $url[0] ."&act=display&id=" .addslashes($_GET["id"]). "&tid=&pagenum=1'>";
						echo " All tags";
						if(addslashes($_GET["tid"]) != "" )
							echo "</a>" ;
						//echo " (".$alltags->Sum.")";
						echo "</li></ul>";
						$cnt =1;	
						echo "<center>";	
						while ($tag = array_shift($tags->items))
						{	
							echo "<span style='font-size:".ceil((6+10*($tag->numitems/$alltags->Sum)))."px'>";
							echo " ";
							if($tag->feed_tag != addslashes($_GET["tid"]))
								echo "<a href='". $url[0] ."&act=display&id=" .addslashes($_GET["id"]). "&tid=".$tag->feed_tag."&pagenum=1'>";
								echo $tag->feed_tag;
							if ($tag->feed_tag != addslashes($_GET["tid"]) )
								echo "</a>";
							echo " ";
							echo "</span>";
							if( $cnt % 3 == 0 )
								echo "<br>";
							$cnt++;
							//echo " (".$tag->numitems.")";
						}
							echo "</center>"; 
						
						?>				
				
				
					<!-- Reading options pane -->
					<h3 class="dbx-handle">Options</h3>
					<form method="post" action="<?php echo $url[0] ."&act=save&id=" . addslashes($_GET["id"]). "&tid=".addslashes($_GET["tid"]); ?>">
						<ul>
							<li>Show at most:
								<ul>
									<li> <input type="text" value="<?php echo $chunk; ?>" size="2" style="width: 1.5em; " name="chunk"> posts<br>
									<li> <input type="text" value="<?php echo $lines; ?>" size="2" style="width: 1.5em; " name="lines"> lines<br>
								</ul>
							</li>
							<li>Enable System logging.
								<ul>
									<?php if ( $options["feedback_trace_enable"] == "Yes") { ?>
										<li><input type='radio' name='prv' checked value='Yes'>Yes</li>
									<?php } else { ?>
										<li><input type='radio' name='prv' value='Yes'>Yes</li>
									<?php } ?>
									<?php if ( $options["feedback_trace_enable"] == "No") { ?>
										<li><input type='radio' name='prv' value='No' checked>No</li>
									<?php } else { ?>
										<li><input type='radio' name='prv' value='No'>No</li>
									<?php } ?>
								</ul>
							</li>
						</ul>						
						<center>
							<h7><input type="submit" name="save" value="Save" class="button button-highlighted"></h7>
						</center>
					</form>
					
					<!-- Aggregated RSS pane -->
					<h3 class="dbx-handle">Aggregated RSS</h3>
					<center>
						<br>
						<a href="<?php echo get_bloginfo('siteurl')."/feedback-rss2.php?category=".addslashes($_GET['tid']); ?>"><img src="<?php echo get_bloginfo('siteurl')."/wp-content/plugins/feedback/"; ?>rss.png" width="40" heigth="40"></a>
					</center>				
		</div><!-- submitbox -->		
		
		<table height="650px"><tr><td valign="top">
			<div id="feeds" class="clearfix" >
	<?php
	// Display currently subscribed feeds...
	// XXX: use single sql command and join, probably faster..
	
	?>
	<?php	
		if( $feed->items )
		{
			$totalchars = 72* $lines;
			while ($post = array_shift($feed->items))
			{       $num_rows = $post->Sum; 		
					$num_pages = ceil($num_rows / $chunk);
										
					
	?>
					<div style="width: 670px">
						<div align="right" ><?php echo date ('D, j M, Y',strtotime($post->pubdate)); ?> from <?php echo $post->channel_name; ?></div>
						<hr>
						<h3> <?php echo $post->title;   ?> </h3>
						<div>   
							 <p>
							<?php  
								if( $_GET["sid"] == "" )
								    echo substr(strip_tags($post->content),0,$totalchars);
								else
								    echo $post->content;
							?>
							 </p>
							<h6>
							<p align="right">Posted in  
								<?php
									$cats->items = $wpdb->get_results("SELECT feed_tag FROM ".$wpdb->prefix."sub_feed_tags WHERE item_id=".$post->id);
									while ($cat = array_shift($cats->items) )
									{
										echo "<a href='". $url[0] ."&act=display&id=&tid=".$cat->feed_tag."&pagenum=1'>";
										echo $cat->feed_tag;
										echo "</a> ";
									}
								?>|
								<?php if( $_GET["sid"] == "") {?>	
								<a href='<?php echo $url[0] ."&act=showpost&sid=" .$post->id; ?>'>Read All </a>|
								<?php }?>
								
								<a href="<?php echo $post->item_uri;  ?>">Visit Channel</a> | <?php echo " <a href='". $url[0] ."&act=reply&id=" .$post->id ."'>" ?>Reply</a>
							</p>
							</h6>
							<br/><br/>					
						</div>
					</div>
	</div> <!-- wrap -->

		<?php			
			}
		}
		else
		{	
			?>
			<div id="message" class="updated fade">
				You do not have any subscription yet. You can subscribe other bloggers via 'Request Subscription' facility under 'My Subscriptons' menu item!
			</div>
			<?php
		}
			
			
		
		?>
		<div>
				<?php 
					
					echo "<br>";
					if( $pagenum_r > 1 )
						echo "<a href='". $url[0] ."&act=display&id=" . addslashes($_GET["id"]). "&tid=".addslashes($_GET["tid"])."&pagenum=".($pagenum_r-1)."'>  Newer Entries </a>";
					
					if($num_pages >=	$pagenum_r+1 )
						echo "<a href='". $url[0] ."&act=display&id=" .addslashes($_GET["id"]) . "&tid=".addslashes($_GET["tid"])."&pagenum=".($pagenum_r+1)."'>  Older Entries </a>";

				?>
			  </div>
	</div>
	</div></td>
</tr>
</table>	
</div> 

<?php

}

function fb_display_offers()
{	
	global $wpdb;
	global $current_user;
	global $wpmuBaseTablePrefix;
	define(MAGPIE_CACHE_ON, 0);

	include_once (ABSPATH . WPINC . '/class-IXR.php');
	include_once (ABSPATH . '/wp-content/plugins/feedback/rss.php'); 
	
		
	$error = "";
	$message = "";
	$act = "";
	

	$options = get_option('feedback_options');

	if (!$options)
	{
		$options = feedback_get_default_options();
	}
	

	if ($_POST["act"])
		$act = $_POST["act"];
	else
		$act = $_GET["act"];

	switch($act)
	{
		case 'accept':
			$offer = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."sub_offers WHERE id='" . addslashes($_GET["id"]) . "'");

			$rpc_feedback_uri = get_feedback_uri($offer->feed_uri);

			if (!$rpc_feedback_uri)
			{
				$error = "No FeedBack URI found in headers or link tags.";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','accepted offer','','".addslashes($offer->feed_uri)."','Failure:".addslashes($error)."',NOW())");
					break;
			}
			$token = save_feed_tokenize($offer->feed_uri, $wpdb);
			if($token)
			{
				// SUCCESS, DELETE OFFER
				$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_offers WHERE id = '" . $offer->id . "'");
				
			}

			// IXR_Client uses parse_url ... which for some reason fails on localhost urls, causing __ to
			// to be appended the file part (xmlrpc.php__) ... so we parse it ourselves. and use host/path params
			$urlparts = good_parse_url($rpc_feedback_uri);
			$client = new IXR_Client($urlparts["host"], $urlparts["path"], $urlparts["port"], 3);
			$client->useragent .= ' -- WordPress/' . $wp_version;
			$resp = $client->query('feedback.request', $offer->feed_uri, $options['feedback_uri'], $token);
			if (!$resp)
			{
				$error = "Error: " . $client->error->code . " (" . $client->error->message . ")";
				if(is_trace_on())
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','accepted offer','','".addslashes($offer->feed_uri)."','Failure:".addslashes($client->error->code) . " (" . addslashes($client->error->message) . ")',NOW())");
			}
			else
			{
				if(is_trace_on())
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','accepted offer','','".addslashes($offer->feed_uri)."','Success',NOW())");
				$message = "Subscription offer successfully accepted!";
			}
				
			break;

		case 'reject':
			// delete from database.. send no request?
			$offer = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."sub_offers WHERE id='" . addslashes($_GET["id"]) . "'");
			$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_offers WHERE id = '" . addslashes($_GET["id"]) . "'");
			if (is_trace_on())
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','rejected offer','','".addslashes($offer->feed_uri)."','Success',NOW())");
			$message = "Subscription offer rejected!";
			break;
		case 'delete':
			// Delete an entire subscription!
			$tokenarr=$wpdb->get_results("SELECT token,channel_name FROM ".$wpdb->prefix."sub_feeds WHERE id = '" . addslashes($_GET["id"]) . "'", ARRAY_A);
			$token=$tokenarr[0]["token"];			
			$channel_name=$tokenarr[0]["channel_name"];
			
			$deletetags= "DELETE ".$wpdb->prefix."sub_feed_tags.* FROM ".$wpdb->prefix."sub_feed_tags, ".$wpdb->prefix."sub_feed_items, ".$wpdb->prefix."sub_feeds". 
					" WHERE ".$wpdb->prefix."sub_feed_tags.item_id = ".$wpdb->prefix."sub_feed_items.id AND ".$wpdb->prefix."sub_feed_items.feed_id = ".$wpdb->prefix."sub_feeds.id". 
					" AND ".$wpdb->prefix."sub_feeds.id=". addslashes($_GET["id"]) ."";
			$wpdb->query($deletetags);
			$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_feed_items WHERE feed_id = '" . addslashes($_GET["id"]) . "'");
			$channel=$wpdb->get_row("SELECT channel_name, channel_link FROM ".$wpdb->prefix."sub_feeds WHERE id = '" . addslashes($_GET["id"]) . "'");
			$wpdb->query("DELETE FROM ".$wpdb->prefix."sub_feeds WHERE id = '" . addslashes($_GET["id"]) . "'");
			if ( is_trace_on() )        
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','deleted subscription','".addslashes($channel->channel_name)."','".addslashes($channel->channel_link)."','Success',NOW())"); 
			$message ="Subscription to ".  $channel_name ." cancelled!";
			break;
		case 'blogroll':			 			
			 $wpdb->query("UPDATE ".$wpdb->prefix."sub_feeds SET blogroll=( blogroll XOR 1) WHERE id=".$_GET["id"]);
			 $channel=$wpdb->get_row("SELECT channel_name, channel_link,blogroll FROM ".$wpdb->prefix."sub_feeds WHERE id = '" . addslashes($_GET["id"]) . "'");
			 if ( is_trace_on() )  
				$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','blogroll','".addslashes($channel->channel_name)."','".addslashes($channel->channel_link)."','to ".addslashes($channel->blogroll)."',NOW())"); 
			 exit();
			 break;
			case 'request':
			$target = $_POST["target"] ? $_POST["target"] : $_GET["target"];
			if (!$target)
			{
				$error = "No target blog URI given";
				if ( is_trace_on() )				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}
			$rpc_feedback_uri = get_feedback_uri($target);

			if (!$rpc_feedback_uri)
			{
				$error = "No FeedBack URI (support) found at site: " . $target;
				if ( is_trace_on() )				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}

			// Get the target feed uri
			$feed_uri = get_feed_uri($target);
			if (!$feed_uri)
			{
				$error = "No feed URI found at site: " . $target . " for subscriptions.";
				if ( is_trace_on() )				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}

			$already = $wpdb->query("SELECT feed_uri FROM ".$wpdb->prefix."sub_feeds WHERE feed_uri='".$feed_uri."'");
			if($already)
			{
				$error = "A request already sent to: " . $target . " for subscriptions.";
				if(is_trace_on())				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}

			$token = save_feed_tokenize($feed_uri, $wpdb);

			if (!$token)
			{
				$error = "Unable to save feed for subscription FeedBack notifications.. blah";
				if(is_trace_on())				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($error)."',NOW())"); 
				break;
			}

			$urlparts = good_parse_url($rpc_feedback_uri);
			$client = new IXR_Client($urlparts["host"], $urlparts["path"], $urlparts["port"], 3);
			$client->useragent .= ' -- WordPress/' . $wp_version;
			$resp = $client->query('feedback.request', $target, $options['feedback_uri'], $token);
			if (!$resp)
			{
				$error = "Error: " . $client->error->code . " (" . $client->error->message . ")";
				if ( is_trace_on() )				  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Failure:".addslashes($client->error->code) . " (" . addslashes($client->error->message) . ")',NOW())"); 
			}
			else
			{	
				if(is_trace_on() )  
					$wpdb->query("INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".addslashes($current_user->nickname)."','requested subscription','','".addslashes($target)."','Success',NOW())"); 
				//echo "INSERT INTO ". $wpmuBaseTablePrefix."feed_stats(feed_local_user,feed_action,feed_remote_channel,feed_remote_channel_uri,description ,time ) VALUES('".$current_user->nickname."','requested subscription','','".$target."','Success',NOW())";
				$message = "Requested feedback notifications and setup subscription for " . $target . " successfully";
			}
				
			break;

		default:
			break;
		}

	$offers = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."sub_offers");
	$subscriptions = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."sub_feeds");
	$url = split("&", $_SERVER["REQUEST_URI"] );
?>


	<script type="text/javascript">
		function ajaxFunction(channel_id)
		{
		var xmlHttp;
		try
		{
		// Firefox, Opera 8.0+, Safari
			xmlHttp=new XMLHttpRequest();
		}
		catch (e)
		{
		// Internet Explorer
		try
			{
				xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
			}
			catch (e)
			{
				try
			{
				xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
		}
		catch (e)
		{
			alert("Your browser does not support AJAX!");
			return false;
		}
		}
		}
	xmlHttp.onreadystatechange=function()
	{
		if(xmlHttp.readyState==4)
			{
			// document.myForm.time.value=xmlHttp.responseText;
				//alert(xmlHttp.responseText);
			}
		}
	//xmlHttp.open("GET","time.asp",true);
	xmlHttp.open("GET",'<?php echo $url[0] ."&act=blogroll&id=" ?>'+channel_id,true);
	xmlHttp.send(null);
}

</script>

<div class="wrap">
<?php

	if ($error)
{
?>
		<div class="error">
			<?php echo $error; ?>
		</div>
<?php
	}
	if ($message)
	{
?>
		<div id="message" class="updated fade">
			<?php echo $message; ?>
		</div>
<?php
	}
?>
	<div class="wrap">
		<h2>Pending Offers</h2>
<?php

	if ($offers)
	{
?>
	<div >
		<table class="widefat">
			<thead>
				<tr>
					<td>Feed</td>
					<td>Time received</td>
					<td>Actions</td>
				</tr>
			</thead>
<?php
	while ($row = array_shift($offers))
	{
		print "<tr><td>" .$row->feed_uri . "</td><td>" . $row->receive_time . "</td>";
		print "<td><a href=\"".$_SERVER["REQUEST_URI"]."&id=".$row->id."&act=accept\">Accept</a>";
		print " <a href=\"".$_SERVER["REQUEST_URI"]."&id=".$row->id."&act=reject\">Reject</a></td></tr>";
	}
?>
	</table>
	</div>
	<?php
	}else {
		echo "No pending offers!";
	}
		
?>

	</div>
	
	<br/><br/>
	
	<div class="wrap">
		<h2>Request subscription</h2>
		<form method="post" action="<?php echo $_SERVER["REQUEST_URI"] ?>">
			<input type="hidden" name="act" value="request" />
			Target blog URI: <input type="text" name="target" /> <input type="submit" value="Send request" class="button button-highlighted"/>
		</form>
		<strong>NEW!</strong>
		<a href="javascript:document.location='<?php echo $_SERVER["FULL_URI"] ?>&act=request&target='+escape(window.location);">FeedMe!</a>
		<!--</div>
		</div> --> 
	</div>
	
	<br/><br/>

	 <div class="wrap">
		<h2>My Subscriptions</h2>

<?php

	if($subscriptions)
	{
?>
	<div>
		<table class="widefat">
			<thead>
				<tr>
					<td>#</td>
					<td>Feed</td>
					<td>Last Update</td>
					<td>Blogroll It</td>
					<td>Subscribed Since</td>
					<td>Actions</td>
				</tr>
			</thead>
<?php
	$i=1;
	while ($row = array_shift($subscriptions))
	{	
		$class='';
		if($i % 2 == 0)
		$class='alternate';
		if($row->blogroll == 1 )
		$isselected = "checked";
		else
		$isselected ="";
		print "<tr class='".$class."'><td>".$i."</td><td>" .$row->channel_name . "</td><td>" . $row->last_update . "</td>";
		print "<td align=center><input type=checkbox name='".$row->id."' value=0 onclick=\"ajaxFunction('".$row->id."')\"".$isselected."></td><td>" . $row->last_update . "</td>";
		print '<td> <a href="'.$_SERVER["REQUEST_URI"].'&act=delete&id=' . $row->id . '">Cancel</a></td></tr>';
		$i++;
	}
?>
		</table>
	</div>
<?php
	}else {
		echo "No subscriptions!";
	}

?>
	 </div>

	<div id="feeds" class="clearfix">
	</div>
<?php	
}

// Hook into publish_post event
add_action('publish_post', 'do_feedbacks');
add_action('deleted_post', 'do_feedbacks'); // ATT: deleted_post is undocumented!
//add_action('edit_post', 'do_feedbacks');  // Seems we don't need it since publish_post also works when a post is editted!
add_action('admin_menu', 'fb_menu');
add_action('init', 'send_fb_header');
add_action('activate_feedback/feedback.php', 'fb_install');
add_action('deactivate_feedback/feedback.php', 'fb_remove');
