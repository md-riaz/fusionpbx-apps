<?php
/* $Id$ */
/*
	call.php
	Copyright (C) 2008, 2009 Mark J Crane
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	James Rose <james.o.rose@gmail.com>

*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
include "app/sms/sms_email.php";

//luarun /var/www/html/app/sms/sms.lua TO FROM 'BODY'

$debug = true;

function route_and_send_sms($from, $to, $body, $media = "")
{
	global $db, $debug, $domain_uuid, $domain_name, $mailsent;

	//create the event socket connection and send the event socket command
	$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
	if (!$fp) {
		//error message
		echo "<div align='center'><strong>Connection to Event Socket failed.</strong></div>";
	} else {
		$mailsent = false;
		// $to = intval(preg_replace('/(^[1])/','', $to));
		$to = intval(preg_replace('/^(\+?1)/', '', $to));
		$from = intval($from);
		$isJson = json_validator($body);
		$body = $isJson ? $body : preg_replace('([\'])', '\\\'', $body); // escape apostrophes
		if ($debug) {
			error_log("TO: " . print_r($to, true));
			error_log("FROM: " . print_r($from, true));
			error_log("BODY: " . print_r($body, true));
		}

		$mailbody =  $isJson ? json_decode($body, true)['body'] : $body;

		if (gettype($media) == "array" && !$isJson) {
			if (empty($body)) {
				$body = "MMS message received, see email for attachment";
			} else {
				$body .= " (MMS message received, see email for attachment)";
			}
			if ($debug) {
				error_log("MMS message (media array present)");
			}
		}

		if ($debug) {
			error_log("BODY: " . print_r($body, true));
		}

		$body = $isJson ? $body : preg_replace('([\n])', '<br>', $body); // escape newlines
		
		if ($debug) {
			error_log("BODY-revised: " . print_r($body, true));
		}

		// Extracted logic to get SMS destination info
		$destination_info = get_sms_destination_info($to);

		if (!$destination_info) {
			die("Invalid Destination");
		}
		$domain_name = $destination_info['domain_name'];
		$domain_uuid = $destination_info['domain_uuid'];
		$match = $destination_info['match'];

		//load default and domain settings
		$_SESSION["domain_uuid"] = $domain_uuid;
		require_once "resources/classes/domains.php";
		$domain = new domains();
		$domain->set();

		if ($debug) {
			error_log("Email from: " . $_SESSION['email']['smtp_from']['text']);
		}
		$mailsent = send_sms_to_email($from, $to, $mailbody, $media);

		//check to see if we have a ring group or single extension
		$sql = "select destination_number ";
		$sql .= "from v_ring_groups, v_ring_group_destinations ";
		$sql .= "where v_ring_groups.ring_group_uuid = v_ring_group_destinations.ring_group_uuid ";
		$sql .= "and ring_group_extension = :extension ";
		$sql .= "and v_ring_groups.domain_uuid = :domain_uuid";
		$prep_statement = $db->prepare(check_sql($sql));
		$prep_statement->execute(array(':extension' => $match[0], ':domain_uuid' => $domain_uuid));
		$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
		if ($debug) {
			error_log("SQL: " . print_r($sql, true));
			error_log("RG RESULT: " . print_r($result, true));
		}

		//send sms via Lua script
		if (count($result)) { //ring group
			foreach ($result as &$row) {
				$switch_cmd = "api luarun app.lua sms inbound ";
				$switch_cmd .= $row['destination_number'] . "@" . $domain_name;
				$switch_cmd .= " " . $from . " '" . $body . "' " . $mailsent;
				if ($debug) {
					error_log(print_r($switch_cmd, true));
				}
				$result2 = trim(event_socket_request($fp, $switch_cmd));
				if ($debug) {
					error_log("RESULT: " . print_r($result2, true));
				}
			}
		} else { //single extension
			$switch_cmd = "api luarun app.lua sms inbound " . $match[0] . "@" . $domain_name . " " . $from . " '" . $body . "' " . $mailsent;
			if ($debug) {
				error_log(print_r($switch_cmd, true));
			}
			$result2 = trim(event_socket_request($fp, $switch_cmd));
			if ($debug) {
				error_log("RESULT: " . print_r($result2, true));
			}
		}

		unset($prep_statement);
	}
}

function json_validator($data)
{
	if (!empty($data)) {
		@json_decode($data);
		return (json_last_error() === JSON_ERROR_NONE);
	}
	return false;
}

// These are the class and functions that were in root.php and removed during change (#168). These are also needed for 
// the sms_hook_xxx.php functions

if (!class_exists('IP4Filter')) {
	class IP4Filter
	{

		private static $_IP_TYPE_SINGLE = 'single';
		private static $_IP_TYPE_WILDCARD = 'wildcard';
		private static $_IP_TYPE_MASK = 'mask';
		private static $_IP_TYPE_CIDR = 'CIDR';
		private static $_IP_TYPE_SECTION = 'section';
		private $_allowed_ips = array();

		public function __construct($allowed_ips)
		{
			$this->_allowed_ips = $allowed_ips;
		}

		public function check($ip, $allowed_ips = null)
		{
			$allowed_ips = $allowed_ips ? $allowed_ips : $this->_allowed_ips;

			foreach ($allowed_ips as $allowed_ip) {
				$type = $this->_judge_ip_type($allowed_ip);
				$sub_rst = call_user_func(array($this, '_sub_checker_' . $type), $allowed_ip, $ip);

				if ($sub_rst) {
					return true;
				}
			}

			return false;
		}

		private function _judge_ip_type($ip)
		{
			if (strpos($ip, '*')) {
				return self::$_IP_TYPE_WILDCARD;
			}

			if (strpos($ip, '/')) {
				$tmp = explode('/', $ip);
				if (strpos($tmp[1], '.')) {
					return self::$_IP_TYPE_MASK;
				} else {
					return self::$_IP_TYPE_CIDR;
				}
			}

			if (strpos($ip, '-')) {
				return self::$_IP_TYPE_SECTION;
			}

			if (ip2long($ip)) {
				return self::$_IP_TYPE_SINGLE;
			}

			return false;
		}

		private function _sub_checker_single($allowed_ip, $ip)
		{
			return (ip2long($allowed_ip) == ip2long($ip));
		}

		private function _sub_checker_wildcard($allowed_ip, $ip)
		{
			$allowed_ip_arr = explode('.', $allowed_ip);
			$ip_arr = explode('.', $ip);
			for ($i = 0; $i < count($allowed_ip_arr); $i++) {
				if ($allowed_ip_arr[$i] == '*') {
					return true;
				} else {
					if (false == ($allowed_ip_arr[$i] == $ip_arr[$i])) {
						return false;
					}
				}
			}
		}

		private function _sub_checker_mask($allowed_ip, $ip)
		{
			list($allowed_ip_ip, $allowed_ip_mask) = explode('/', $allowed_ip);
			$begin = (ip2long($allowed_ip_ip) & ip2long($allowed_ip_mask)) + 1;
			$end = (ip2long($allowed_ip_ip) | (~ip2long($allowed_ip_mask))) + 1;
			$ip = ip2long($ip);
			return ($ip >= $begin && $ip <= $end);
		}

		private function _sub_checker_section($allowed_ip, $ip)
		{
			list($begin, $end) = explode('-', $allowed_ip);
			$begin = ip2long($begin);
			$end = ip2long($end);
			$ip = ip2long($ip);
			return ($ip >= $begin && $ip <= $end);
		}

		private function _sub_checker_CIDR($CIDR, $IP)
		{
			list($net, $mask) = explode('/', $CIDR);
			return (ip2long($IP) & ~((1 << (32 - $mask)) - 1)) == ip2long($net);
		}
	}
}

function check_acl()
{
	global $db, $debug, $domain_uuid, $domain_name;

	//select node_cidr from v_access_control_nodes where node_cidr != '';
	$sql = "select node_cidr from v_access_control_nodes where node_cidr != '' and node_type = 'allow'";
	$prep_statement = $db->prepare(check_sql($sql));
	$prep_statement->execute();
	$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
	if (count($result) == 0) {
		die("No ACL's");
	}
	foreach ($result as &$row) {
		$allowed_ips[] = $row['node_cidr'];
	}

	$acl = new IP4Filter($allowed_ips);

	return $acl->check($_SERVER['REMOTE_ADDR'], $allowed_ips);
}

function get_sms_destination_info($to)
{

	global $db, $debug, $domain_uuid, $domain_name, $match;

	$match = array();

	// Check for chatplan_detail in sms_destinations table
	$sql = "select domain_name, ";
	$sql .= "chatplan_detail_data, ";
	$sql .= "v_sms_destinations.domain_uuid as domain_uuid ";
	$sql .= "from v_sms_destinations, ";
	$sql .= "v_domains ";
	$sql .= "where v_sms_destinations.domain_uuid = v_domains.domain_uuid";
	$sql .= " and destination like :to";
	$sql .= " and chatplan_detail_data <> ''";

	if ($debug) {
		error_log("SQL: " . print_r($sql, true));
	}

	$prep_statement = $db->prepare(check_sql($sql));
	$prep_statement->bindValue(':to', "%{$to}%");
	$prep_statement->execute();
	$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);

	if (count($result) > 0) {
		foreach ($result as &$row) {
			$domain_name = $row["domain_name"];
			preg_match('/(\d{2,7})/', $row["chatplan_detail_data"], $match);
			$domain_uuid = $row["domain_uuid"];
			break; //limit to 1 row
		}
	} else { // Fall back to destinations table for backwards compatibility
		$sql = "select domain_name, ";
		$sql .= "dialplan_detail_data, ";
		$sql .= "v_domains.domain_uuid as domain_uuid ";
		$sql .= "from v_destinations, ";
		$sql .= "v_dialplan_details, ";
		$sql .= "v_domains ";
		$sql .= "where v_destinations.dialplan_uuid = v_dialplan_details.dialplan_uuid ";
		$sql .= "and v_destinations.domain_uuid = v_domains.domain_uuid";
		$sql .= " and destination_number like :to and dialplan_detail_type = 'transfer'";

		if ($debug) {
			error_log("SQL: " . print_r($sql, true));
		}

		$prep_statement = $db->prepare(check_sql($sql));
		$prep_statement->bindValue(':to', "%{$to}%");
		$prep_statement->execute();
		$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
		if (count($result) == 0) {
			error_log("Cannot find a destination: " . print_r($result, true));
			die("Invalid Destination");
		}
		foreach ($result as &$row) {
			$domain_name = $row["domain_name"];
			preg_match('/(\d{2,7})/', $row["dialplan_detail_data"], $match);
			$domain_uuid = $row["domain_uuid"];
			break; //limit to 1 row
		}
	}
	unset($prep_statement);

	if ($debug) {
		error_log("SQL: " . print_r($sql, true));
		error_log("MATCH: " . print_r($match[0], true));
		error_log("DOMAIN_NAME: " . print_r($domain_name, true));
		error_log("DOMAIN_UUID: " . print_r($domain_uuid, true));
	}

	return [
		'domain_name' => $domain_name,
		'domain_uuid' => $domain_uuid,
		'match' => $match
	];
}
