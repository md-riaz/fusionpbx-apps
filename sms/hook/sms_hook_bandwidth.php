<?php

//includes files
require_once dirname(__DIR__, 3) . "/resources/require.php";
require_once "../sms_hook_common.php";

if ($debug) {
	error_log('[SMS] REQUEST: ' . print_r($_SERVER, true));
}

if (check_acl()) {
	if (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
		$data = json_decode(file_get_contents("php://input"));
	
		http_response_code(200);
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();  // ✅ Bandwidth gets HTTP 200 immediately
		}
	
		if ($debug) {
			error_log('[SMS] REQUEST: ' . print_r($data, true));
		}
	
		// Normalize $data to always be an array of message objects
		$messages = is_array($data) ? $data : [$data];
	
		foreach ($messages as $msg) {
			$message_id = $msg->message->id ?? null;
			$from = $msg->message->from ?? null;
			$to = $msg->message->owner ?? null;
			$text = $msg->message->text ?? null;
			$msg_type = $msg->type ?? null;
			$desc = $msg->description ?? null;
	
	
			/**
			 * Bandwidth uses HTTP Callbacks webhooks to send events to any publicly addressable url, as defined in 
			 * your messaging application. All Message callbacks are sent as a list/array [ {message metadata} ] 
			 * to the webhook url in the application. You MUST Reply with a HTTP 2xx status code for every 
			 * callback/delivery receipt. Bandwidth will retry every callback over the next 24 hours until a HTTP 2xx 
			 * code is received for the callback. After 24 hours, Bandwidth will no longer try to send the callback.
			 * Bandwidth's Messaging platform has a 10 second timeout for callbacks. This means your server must 
			 * respond to the callback request within 10 seconds, otherwise the platform will try again at a later time.
			 * 
			 * https://dev.bandwidth.com/docs/messaging/webhooks#message-failed/
			 */
	
			switch (strtolower($msg_type)) {
				case 'message-delivered': {
						//				$text .= "Message was delivered";
						//				route_and_send_sms($from, $to, $text);
						return http_response_code(200);
						break;
					}
				case 'message-failed': {
	
						$text = "#$message_id Message failed to be delivered (" . $from . " to " . $to . ")";;
						$text .= " Reason: " . ucfirst(str_replace('-', ' ', $desc));
						error_log("Message Failed to send " . print_r($data, false));
						route_and_send_sms($from, $to, $text);
	
						return http_response_code(200);
						break;
					}
				default: {
						route_and_send_sms($from, $to, $text);
						return http_response_code(200);
					}
			}
		}
	} else {
		error_log('[SMS] REQUEST: No SMS Data Received');
		die("no");
	}
} else {
	error_log('ACCESS DENIED [SMS]: ' . print_r($_SERVER['REMOTE_ADDR'], true));
	die("access denied");
}
