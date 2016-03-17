<?php

elgg_register_event_handler('init','system','cp_notifications_init');

function cp_notifications_init() {
	elgg_register_css('cp_notifications-css','mod/cp_notifications/css/notifications-table.css');
	elgg_register_plugin_hook_handler('register', 'menu:entity', 'notify_entity_menu_setup', 400);

	$actions_base = elgg_get_plugins_path() . 'cp_notifications/actions/cp_notifications';
	elgg_register_action('cp_notify/subscribe', "$actions_base/subscribe.php");
	elgg_register_action('cp_notify/unsubscribe', "$actions_base/unsubscribe.php");
	elgg_register_action('user/requestnewpassword', "$actions_base/request_new_password.php", 'public');

	elgg_register_plugin_hook_handler('email', 'system', 'cpn_email_handler_hook');	// intercepts and blocks emails and notifications to be sent out
	elgg_register_event_handler('create','object','cp_create_notification');	// send notifications when the action is sent out
	elgg_register_event_handler('create','annotation','cp_create_annotation_notification');	// likes notification

	elgg_register_action('groups/email_invitation', "$actions_base/manual_send.php");

	// TODO: check if group tools is installed
	elgg_register_event_handler('create', 'membership_request', 'cp_membership_request');
	//elgg_register_event_handler('update', 'annotation', 'cp_create_metadata_notification');
	elgg_register_event_handler('group_tools/invite_members', 'group', 'cp_group_join_notification');

	if (elgg_is_active_plugin('mentions')) {	// we need to check if the mention plugin is installed and activated because it does notifications differently...
		elgg_unregister_event_handler('create', 'object','mentions_notification_handler');
		elgg_unregister_event_handler('update', 'annotation','mentions_notification_handler');
	}

	if (elgg_is_active_plugin('thewire_tools'))
		elgg_unregister_event_handler('create', 'object', 'thewire_tools_create_object_event_handler');

	// since most of the notifications are built within the action file itself, the trigger_plugin_hook was added to respected plugins
	elgg_register_plugin_hook_handler('cp_overwrite_notification', 'all', 'cp_overwrite_notification_hook');
}






function cp_overwrite_notification_hook($hook, $type, $value, $params) {
	$cp_msg_type = trim($params['cp_msg_type']);

	switch($cp_msg_type) {
		case 'cp_group_add':
			$subject = "{$cp_user->name} has added you into their group '{$cp_group->name}'";
			$message = array(
				'cp_user_added' => $params['cp_user_added'],
				'cp_user' => $params['cp_user'],
				'cp_group' => $params['cp_group'],
				'cp_message' => $params['cp_added_msg'],
				'cp_msg_type' => $cp_msg_type
			);
			break;
		
		case 'cp_group_invite':
			$subject = "{$cp_group_invite_from->name} has invited you to join their group '{$cp_group->name}'";
			$message = array(
				'cp_group_invite_from' => $params['cp_invitee'],
				'cp_group_invite_to' => $params['cp_inviter'],
				'cp_group' => $params['cp_invite_to_group'],
				'cp_invitation_url' => $params['cp_invitation_url'],
				'cp_invitation_msg' => $params['cp_invite_msg']
			);
			break;

		case 'cp_group_invite_email':
			$subject = "{$cp_email_invited_by->name} has invited you to join their group '{$cp_group_invite->name}'";
			$message = array(
				'cp_email_invited' => $params['cp_invitee'],
				'cp_email_invited_by' => $params['cp_inviter'],
				'cp_group_invite' => $params['cp_group_invite'],
				'cp_invitation_non_user_url' => $params['cp_invitation_nonuser_url'],
				'cp_invitation_url' => $params['cp_invitation_url'],
				'cp_invitation_code' => $params['cp_invitation_code'],
				'cp_invitation_msg' => $params['cp_invitation_msg']
			);
			break;

		case 'cp_group_mail':
			$subject = "{$cp_group->name} has sent out a group message '{$cp_group_subject}'";
			$message = array(
				'cp_group' => $params['cp_group'],
				'cp_group_subject' => $params['cp_group_subject'],
				'cp_group_message' => $params['cp_group_message'],
				'cp_group_user' => $params['cp_group_mail_users']
			);
			break;

		case 'cp_friend_request':
			$message = array(
				'cp_friend_request_from' => $params['cp_friend_requester'],
				'cp_friend_request_to' => $params['cp_friend_receiver'],
				'cp_friend_invitation_url' => $params['cp_friend_invite_url']
			);
			$subject = "{$cp_friend_request_from} has sent you a friend request";
			break;

		case 'cp_forgot_password':
			$message = array(
				'cp_password_request_user' => $params['cp_password_requester'],
				'cp_password_request_ip' => $params['cp_requester_ip'],
				'cp_password_request_url' => $params['cp_password_request_url']
			);
			$subject = "You have requested a password reset";
			break;

		case 'cp_validate_user':
			$message = array(
				'cp_validate_user' => $params['cp_validate_user'],
				'cp_validate_url' => $params['cp_validate_url']
			);
			$subject = "Please validate account for {$cp_validate_user->email}";
			break;

		default:
			break;
	}

	$template = elgg_view('cp_notifications/email_template', $message);

	foreach ($to_recipients as $to_recipient) {
		$from_user = elgg_get_site_entity()->name.' <'.elgg_get_site_entity()->email.'>';
		$to_user = $to_recipient;
		$mail_result = elgg_send_email($from_user,$to_user,$subject,$template);
	} // end foreach loop
}


// membership only notification
function cp_membership_request($event, $type, $object) {
	$site = elgg_get_site_entity();
	$request_user = get_user($object->guid_one); // user who sends request to join
	$group_request = get_entity($object->guid_two);	// group that is being requested

	$message = array(
					'cp_group_req_user' => $request_user,
					'cp_group_req_group' => $group_request,
					'cp_msg_type' => 'cp_closed_grp_req_type',
				);

	$template = elgg_view('cp_notifications/email_template', $message);
	$subject = "{$request_user} has requested to join your group";

	$from_user = elgg_get_site_entity()->name.' <'.elgg_get_site_entity()->email.'>';
	$to_user = get_user($group_request->owner_guid);

	$mail_result = elgg_send_email($from_user,$to_user->email,$subject,$template); // email
	messages_send($subject, $template, $to_user->getGUID(), $site->getGUID()); // site mail
}




function cp_create_annotation_notification($event, $type, $object) {
	error_log("aaaaaa : ".$object->getSubtype());
	$site = elgg_get_site_entity();
	$do_not_subscribe_list = array('blog_revision','discussion_reply','task','vote');	// we dont need to be notified so many times
	if (in_array($object->getSubtype(), $do_not_subscribe_list))
		return $return;

	$object_subtype = $object->getSubtype();
	switch ($object_subtype) {
		case 'likes':
			$entity = get_entity($object->entity_guid);
			$user = get_user($entity->owner_guid);
			$to_recipients[] = $user;

			$from_user = get_user($object->owner_guid);
			$subject = "{$from_user->name} liked your post '{$entity->title}'!";
			$message = array('cp_topic_title' => $entity->title,
								'cp_liked_by' => $from_user->name,
								'cp_topic_description' => $object->description,
								'cp_topic_author' => $object->owner_guid,
								'cp_topic_url' => $entity->getURL(),
								'cp_msg_type' => 'cp_likes_type',
						);
			break;
	} // end switch case

	$template = elgg_view('cp_notifications/email_template', $message); // pass in the information into the template to prepare the notification

	foreach ($to_recipients as $to_recipient) {
		$from_user = elgg_get_site_entity()->name.' <'.elgg_get_site_entity()->email.'>';
		elgg_send_email($from_user,$to_recipient->email,$subject,$template);

		if (elgg_is_active_plugin('messages')) {
			messages_send($subject, $template, $to_recipient->guid, $site->email);
		}
	}
} // end of function





function cp_create_notification($event, $type, $object) {
	$do_not_subscribe_list = array('poll_choice','blog_revision');
	if (in_array($object->getSubtype(), $do_not_subscribe_list))
		return $return;

	$site = elgg_get_site_entity();
	$to_recipients = array();

	switch ($object->getSubtype()) {
		case 'discussion_reply':
		case 'comment':	// when someone makes a comment in an entity
			if (elgg_is_active_plugin('mentions')) // if mentions plugin is enabled... check to see if there were any mentions
				$cp_mentioned_users = cp_scan_mentions($object);

			$container_entity = get_entity($object->getContainerGUID());	// get topic that the comment resides in
			$options = array(
				'relationship' => 'cp_subscribed_to_email',
				'relationship_guid' => $container_entity->getGUID(),
				'inverse_relationship' => true,
				'limit' => 0	// no limit
			);
			
			// prepare all the emails that needs to be sent
			$users = elgg_get_entities_from_relationship($options);
			
			foreach ($users as $user) 
				$to_recipients[] = $user->email;
			$to_recipients[] = get_user($container_entity->owner_guid)->email;
			
			$reply_author = get_user($object->owner_guid);
			$subject = "{$reply_author->name} has posted a comment or reply to '{$container_entity->title}'";
	
			$message = array('cp_topic_title' => $container_entity->title, 
								'cp_topic_author' => $container_entity->owner_guid, 
								'cp_topic_description' => $container_entity->description, 
								'cp_comment_author' => $object->owner_guid, 
								'cp_comment_description' => $object->description,
								'cp_topic_url' => $container_entity->getURL(),
								'cp_msg_type' => 'cp_reply_type',
						);
			break;


		case 'messages':	// sending site mail to another user
			
			$to_username = get_input('recipient_username');
			$to_recipients[] = get_user_by_username($to_username)->email;
			$from_user = get_user($object->owner_guid);
			$subject = "{$from_user->name} has sent you a new message {$object->title}!";
			$message = array('cp_topic_title' => $object->title,
								'cp_topic_description' => $object->description,
								'cp_topic_author' => $object->owner_guid,
								'cp_topic_url' => $object->getURL(),
								'cp_msg_type' => 'cp_site_msg_type',
						);
			$template = elgg_view('cp_notifications/email_template', $message);
			break;

		default:	// creating entities such as blogs, topics, bookmarks, etc...

			if (elgg_is_active_plugin('mentions')) // check to see if there were any mentions
				$cp_mentioned_users = cp_scan_mentions($object);

			// groups
			if ($object->getContainerEntity() instanceof ElggGroup) {
				error_log("group related");
				$options = array(
					'relationship' => 'cp_subscribed_to_email',
					'relationship_guid' => $object->getContainerGUID(),
					'inverse_relationship' => true,
					'limit' => 0
				);
			}

			$users = elgg_get_entities_from_relationship($options);

			// subscribers
			$options = array(
				'relationship' => 'cp_subscribed_to_email',
				'relationship_guid' => $object->getGUID(),
				'types' => 'user',
				'inverse_relationship' => true,
				'limit' => 0,
			);

			$subscribers = elgg_get_entities_from_relationship($options);

			foreach ($users as $user)
				$to_recipients[] = $user->email;

			foreach ($subscribers as $subscriber) {
				if (in_array($subscriber->email, $to_recipients))
					$to_recipients[] = $subscriber->email;
			}

			$user = get_user($object->owner_guid);
			$subject = "{$user->name} has posted something new entitled '{$object->title}'";
			$message = array('cp_topic_title' => $object->title, 
								'cp_topic_author' => $object->owner_guid, 
								'cp_topic_description' => $object->description, 
								'cp_topic_url' => $object->getURL(),
								'cp_msg_type' => 'cp_new_type',
						);		
		break;
	}

	$template = elgg_view('cp_notifications/email_template', $message); // pass in the information into the template to prepare the notification
	
	if ($cp_mentioned_users) {
		foreach ($cp_mentioned_users as $cp_mentioned_user) {

			$user_mentioned = preg_replace('/[^A-Za-z1-9\.\-]/','',$cp_mentioned_user);	// there will always be that extra special character to remove
			$user_mentioned = get_user_by_username($user_mentioned);

			$user_mentioner = $object->getOwnerGUID();
			$user_mentioner = get_user($user_mentioner);
			$subject_mention = "{$user_mentioner->name} has mentioned you in their new post/reply";

			$message_mention = array('cp_topic_title' => $object->getContainerEntity()->title,
										'cp_topic_author' => $object->owner_guid,
										'cp_topic_description' => $object->description,
										'cp_topic_url' => $object->getURL(),
										'cp_msg_type' => 'cp_mention_type'
						);

			$template_mention = elgg_view('cp_notifications/email_template', $message_mention);
			messages_send($subject_mention, $template_mention, $user_mentioned->guid, 0);
			$from_user = elgg_get_site_entity()->name.' <'.elgg_get_site_entity()->email.'>';
			elgg_send_email($from_user,$to_recipient,$subject,$template);
		}
	}


	foreach ($to_recipients as $to_recipient) { 
		if ($to_recipient) {
			$from_user = elgg_get_site_entity()->name.' <'.elgg_get_site_entity()->email.'>';
			elgg_send_email($from_user,$to_recipient,$subject,$template);
			$to_user = get_user_by_email($to_recipient); // this function gets an array of users
			messages_send($subject, $template, $to_user[0]->getGUID(), 0);
		}
	}	

	return true;
} // end of function




function cp_scan_mentions($cp_object) {
	$fields = array('title','description','value');
	foreach($fields as $field) {
		$content = $cp_object->$field;	// pull the information from the fields saved to object
		if (preg_match_all("/\@([A-Za-z1-9]*).?([]A-Za-z1-9]*)/", $content, $matches)) { // find all the string that matches: @christine.yu
			return $matches[0];
		}
	}
	return false;
}



// intercepts all email and stops emails from sending
function cpn_email_handler_hook($hook, $type, $notification, $params) {
	return false;
}


function notify_entity_menu_setup($hook, $type, $return, $params) {
	$entity = $params['entity'];
	$do_not_subscribe_list = array('comment','discussion_reply');
	if (elgg_in_context('widgets') || in_array($entity->getSubtype(), $do_not_subscribe_list))
		return $return;

	if ($entity->getContainerEntity() instanceof ElggGroup || $entity instanceof ElggGroup || $entity->getContainerEntity() instanceof ElggUser) {	// only want to receive notification if it's in group or by user
		if (check_entity_relationship(elgg_get_logged_in_user_guid(), 'cp_subscribed_to_email', $entity->getGUID())) {
			// TODO: implement site mail notification too

			if (elgg_is_active_plugin('wet4')) 
				$bell_status = '<i class="fa fa-bell-slash-o"></i>';
			else
				$bell_status = 'Stop Subscribing';
			
			$return[] = ElggMenuItem::factory(array(
				'name' => 'unset_notify',
				'href' => elgg_add_action_tokens_to_url("/action/cp_notify/unsubscribe?guid={$entity->guid}"),
				'text' => $bell_status,
				'title' => 'Stop Subscribing',
				'priority' => 1000,
				'class' => '',
				'item_class' => ''
			));
		} else {

			if (elgg_is_active_plugin('wet4')) 
				$bell_status = '<i class="fa fa-bell-o"></i>';
			else
				$bell_status = 'Subscribe Now!';

			$return[] = ElggMenuItem::factory(array(
				'name' => 'set_notify',
				'href' => elgg_add_action_tokens_to_url("/action/cp_notify/subscribe?guid={$entity->guid}"),
				'text' => $bell_status,	
				'title' => 'Subscribe Now!',
				'priority' => 1000,
				'class' => '',
				'item_class' => ''
			));
		}
	}

	return $return;
}