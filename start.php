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

	elgg_register_event_handler('create', 'membership_request', 'cp_membership_request');
	if (elgg_is_active_plugin('group_tools'))
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






// these notifications cannot be set from the settings page for the user, MUST be sent out
function cp_overwrite_notification_hook($hook, $type, $value, $params) {
	
	$cp_msg_type = trim($params['cp_msg_type']);
	error_log("=== cp_overwrite_notification_hook === msg-type: {$cp_msg_type}");
	$subject = "";
	$to_recipients = array();
	$email_only = false;	// some notifications require only sending off emails
	$to_recipients = array();
	switch($cp_msg_type) {

		case 'cp_friend_approve': // friend_request/actions/approve

			$subject = elgg_echo('cp_notify:subject:approve_friend',array($params['cp_approver']),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:approve_friend',array($params['cp_approver']),'fr');
			$message = array(
				'cp_approver' => $params['cp_approver'],
				'cp_approver_profile' => $params['cp_approver_profile'],
				'cp_msg_type' => $cp_msg_type,
				);
			$to_recipients[] = get_user($params['cp_request_guid']);
			break;


		case 'cp_site_msg_type':	// messages/actions/messages/send.php
			$to_recipients[] = get_user($params['cp_to']['guid']);
			$subject = elgg_echo('cp_notify:subject:site_message',array($params['cp_from']['name'],$params['cp_topic_title']),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:site_message',array($params['cp_from']['name'],$params['cp_topic_title']),'fr');

			$message = array('cp_topic_title' => $params['cp_topic_title'],
								'cp_topic_description' => $params['cp_topic_description'],
								'cp_topic_author' => $params['cp_from']['name'],
								'cp_topic_url' => $params['cp_topic_url'],
								'cp_msg_type' => 'cp_site_msg_type',
						);
			break;


		case 'cp_group_add':	// group_tools/lib/functions.php OR groups/actions/groups/membership/add.php ????
			$to_recipients[] = $params['cp_user_added'];
			$subject = elgg_echo('cp_notify:subject:group_add_user',array($params['cp_group']['name']),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:group_add_user',array($params['cp_group']['name']),'fr');

			$message = array(
				'cp_user_added' => $params['cp_user_added'],
				'cp_group' => $params['cp_group'],
				'cp_message' => $params['cp_added_msg'],
				'cp_msg_type' => $cp_msg_type
			);
			//$template = elgg_view('cp_notifications/email_template',$message);
			break;

		
		case 'cp_group_invite': // group_tools/lib/functions.php
			$subject = elgg_echo('cp_notify:subject:group_invite_user',array($params['cp_inviter']['name'],$params['cp_invite_to_group']['name']),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:group_invite_user',array($params['cp_inviter']['name'],$params['cp_invite_to_group']['name']),'fr');
			$message = array(
				'cp_group_invite_from' => $params['cp_invitee'], // user we're inviting
				'cp_group_invite_to' => $params['cp_inviter'], // user inviting others
				'cp_group' => $params['cp_invite_to_group'],
				'cp_invitation_url' => $params['cp_invitation_url'],
				'cp_invitation_msg' => $params['cp_invite_msg'],
				'cp_msg_type' => $cp_msg_type
			);
			$to_recipients[] = get_user($params['cp_invitee']['guid']);
			break;


		case 'cp_group_invite_email': // group_tools/lib/functions.php (returns user's email, so return after mail is sent out)
			$subject = elgg_echo('cp_notify:subject:group_invite_email',array($params['cp_inviter']['name'],$params['cp_group_invite']['name']),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:group_invite_email',array($params['cp_inviter']['name'],$params['cp_group_invite']['name']),'fr');
			$message = array(
				'cp_email_invited' => $params['cp_invitee'],
				'cp_email_invited_by' => $params['cp_inviter'],
				'cp_group_invite' => $params['cp_group_invite'],
				'cp_invitation_non_user_url' => $params['cp_invitation_nonuser_url'],
				'cp_invitation_url' => $params['cp_invitation_url'],
				'cp_invitation_code' => $params['cp_invitation_code'],
				'cp_invitation_msg' => $params['cp_invitation_msg'],
				'cp_msg_type' => $cp_msg_type
			);
			$template = elgg_view('cp_notifications/email_template', $message);
			mail($params['cp_invitee'],$subject,$template,cp_get_headers());
			return true;


		case 'cp_group_mail': // group_tools/actions/mail.php
			$message = array(
				'cp_group' => $params['cp_group'],
				'cp_group_subject' => $params['cp_group_subject'],
				'cp_group_message' => $params['cp_group_message'],
				'cp_group_user' => $params['cp_group_mail_users'],
				'cp_msg_type' => $cp_msg_type
			);
			$subject = elgg_echo('cp_notify:subject:group_mail',array($params['cp_group']['name'],$params['cp_group_subject']),'en');
            $subject .= ' | '.elgg_echo('cp_notify:subject:group_mail',array($params['cp_group']['name'],$params['cp_group_subject']),'fr');
			foreach ($params['cp_group_mail_users'] as $to_user)
				$to_recipients[] = get_user($to_user);
			break;


		case 'cp_friend_request':
			$message = array(
				'cp_friend_request_from' => $params['cp_friend_requester'],
				'cp_friend_request_to' => $params['cp_friend_receiver'],
				'cp_friend_invitation_url' => $params['cp_friend_invite_url'],
				'cp_msg_type' => $cp_msg_type
			);
			$from_user = $params['cp_friend_requester'];
			$to_user = $params['cp_friend_receiver'];
			$subject = elgg_echo('cp_notify:subject:friend_request',array($from_user['name']));
			$to_recipients[] = get_user($to_user['guid']);
			break;


		case 'cp_forgot_password': // send email notifications only - /wet4/users/action/password
			cp_send_new_password_request($params['cp_user_pass_req_guid']);
			return true; // this is the only special case that will need to end early in function


		case 'cp_validate_user': // uservalidationbyemail/lib/functions.php
			$message = array(
				'cp_validate_user' => $params['cp_validate_user'],
				'cp_validate_url' => $params['cp_validate_url'],
				'cp_msg_type' => $cp_msg_type
			);
			$subject = elgg_echo('cp_notify:subject:validate_user',array($params['cp_validate_user']['email']),'en');
            $subject .= ' | '. elgg_echo('cp_notify:subject:validate_user',array($params['cp_validate_user']['email']),'fr');
			$to_recipients[] = get_user($params['cp_validate_user']['guid']);
			$email_only = true;
			break;


		case 'cp_hjtopic': // gcforums/actions/gcforums/create.php
			$message = array(
				'cp_hjtopic_author' => $params['cp_topic_author'],
				'cp_hjtopic_title' => $params['cp_topic_title'],
				'cp_hjtopic_description' => $params['cp_topic_description'],
				'cp_hjtopic_url' => $params['cp_topic_url'],
				'cp_msg_type' => $cp_msg_type
				);
			$t_user = $params['cp_subscribers'];
			foreach ($t_user as $s_uer)
				$to_recipients[] = get_user($s_uer);
			$subject = elgg_echo('cp_notify:subject:hjtopic',array(),'en'); // translate
			$subject .= ' | '.elgg_echo('cp_notify:subject:hjtopic',array(),'fr');
			break;


		case 'cp_hjpost': // gcforums/actions/gcforums/create.php
			$message = array(
				'cp_hjtopic_author' => $params['cp_topic_author'],
				'cp_hjtopic_title' => $params['cp_topic_title'],
				'cp_hjtopic_description' => $params['cp_topic_description'],
				'cp_hjtopic_url' => $params['cp_topic_url'],
				'cp_msg_type' => $cp_msg_type
				);
			$t_user = $params['cp_subscribers'];
			foreach ($t_user as $s_uer)
				$to_recipients[] = get_user($s_uer);
			$subject = elgg_echo('cp_notify:subject:hjpost',array(),'en'); // translate
			$subject .= ' | '.elgg_echo('cp_notify:subject:hjpost',array(),'fr');
			break;
		
		default:
			break;
	}

	$template = elgg_view('cp_notifications/email_template', $message);
	foreach ($to_recipients as $to_recipient) {
		error_log("sending >>>>>> >>>>>> >>>>>> >>>>> {$to_recipient->email}");
		mail($to_recipient->email,$subject,$template,cp_get_headers());
		if (!$email_only)
			messages_send($subject, $template, $to_recipient->guid, $site->email);
	} // end foreach loop
}







function cp_create_annotation_notification($event, $type, $object) {

	$subject = "";
	$site = elgg_get_site_entity();

	$do_not_subscribe_list = array('blog_revision','discussion_reply','task','vote');	// we dont need to be notified so many times
	if (in_array($object->getSubtype(), $do_not_subscribe_list))
		return $return;

	$object_subtype = $object->getSubtype();
	// cyu - removed likes for people who are friending others
	switch ($object_subtype) {
		case 'likes':
		    // TODO: need to look into this portion of code... newsfeed does not work on my local - cyu
            $entity = get_entity($object->entity_guid);
            
            if($entity->type === "user"){
                $user = get_user($entity->guid);  
                
                $options=array(
                    'relationship' => 'friend',
                    'relationship_guid' => $object->entity_guid,
                    );
                $recipient = elgg_get_entities_from_relationship($options);

                //$to_recipients[] =  get_user($object->owner_guid);
                $to_recipients[] =  $recipient;
            
            } else {
			    $user = get_user($entity->owner_guid);
                $to_recipients[] = $user;
			}
            

			$from_user = get_user($object->owner_guid);
            if($entity->type === "user"){
                $subject = elgg_echo('cp_notify:subject:likes',array($from_user->name, $entity->name),'en');
                $subject .= ' | '.elgg_echo('cp_notify:subject:likes',array($from_user->name,$entity->name),'fr');
            
            } else {
               
                $subject = elgg_echo('cp_notify:subject:likes',array($from_user->name,$entity->title),'en');
                $subject .= ' | '.elgg_echo('cp_notify:subject:likes',array($from_user->name,$entity->title),'fr');
            
            }
			$message = array('cp_topic_title' => $entity->title,
								'cp_liked_by' => $from_user->name,
								'cp_topic_description' => $object->description,
								'cp_topic_author' => $object->owner_guid,
								'cp_topic_url' => $entity->getURL(),
								'cp_msg_type' => 'cp_likes_type',
						);
			break;
		default:
			break;
	} // end switch case

	$template = elgg_view('cp_notifications/email_template', $message); // pass in the information into the template to prepare the notification

	foreach ($to_recipients as $to_recipient) {
		mail($to_recipient->email,$subject,$template,cp_get_headers());
		if (elgg_is_active_plugin('messages')) {
			messages_send($subject, $template, $to_recipient->guid, $site->email);
		}
	}
} // end of function






function cp_create_notification($event, $type, $object) {
	error_log("=== cp_create_notification === msg-type: {$object->getSubtype()}");
	$subject = "";
	$do_not_subscribe_list = array('poll_choice','blog_revision','widget');
	if (in_array($object->getSubtype(), $do_not_subscribe_list))
		return $return;

	$site = elgg_get_site_entity();
	$to_recipients = array();

	switch ($object->getSubtype()) {
		case 'discussion_reply':
		case 'comment':	// when someone makes a comment in an entity
			if (elgg_is_active_plugin('mentions') && $object->getSubtype() !== 'messages') // if mentions plugin is enabled... check to see if there were any mentions
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
				$to_recipients[] = $user;
			$to_recipients[] = get_user($container_entity->owner_guid);
			
			$reply_author = get_user($object->owner_guid);
			
			$subject = elgg_echo('cp_notify:subject:comments',array($reply_author->name,$container_entity->title),'en');
			$subject .= ' | '.elgg_echo('cp_notify:subject:comments',array($reply_author->name,$container_entity->title),'fr');

			$message = array('cp_topic_title' => $container_entity->title, 
								'cp_topic_author' => $container_entity->owner_guid, 
								'cp_topic_description' => $container_entity->description, 
								'cp_comment_author' => $object->owner_guid, 
								'cp_comment_description' => $object->description,
								'cp_topic_url' => $container_entity->getURL(),
								'cp_msg_type' => 'cp_reply_type',
						);
			break;

		default:	// creating entities such as blogs, topics, bookmarks, etc...

			if (elgg_is_active_plugin('mentions') && $object->getSubtype() !== 'messages') // check to see if there were any mentions
				$cp_mentioned_users = cp_scan_mentions($object);

			if ($object->getContainerEntity() instanceof ElggGroup) {
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
				$to_recipients[] = $user;

			foreach ($subscribers as $subscriber) {
				if (in_array($subscriber->email, $to_recipients))
					$to_recipients[] = $subscriber;
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
			$user_mentioned = get_user_by_username($user_mentioned);	// get the user entity through username

			$user_mentioner = $object->getOwnerGUID();
			$user_mentioner = get_user($user_mentioner);

			$subject_mention = elgg_echo('cp_notify:subject:mention',array($user_mentioner->name));
			$subject_mention .= ' | '.elgg_echo('cp_notify:subject:mention',array($user_mentioner->name));

			$message_mention = array('cp_topic_title' => $object->getContainerEntity()->title,
			                       'cp_topic_author' => $object->owner_guid,
			                       'cp_topic_description' => $object->description,
			                       'cp_topic_url' => $object->getURL(),
			                       'cp_msg_type' => 'cp_mention_type'
			       );

			$template_mention = elgg_view('cp_notifications/email_template', $message_mention);

			mail($user_mentioned->email,$subject_mention,$template,cp_get_headers());
			messages_send($subject_mention, $template, $user_mentioned->getGUID(), 0);
		}
    }

	foreach ($to_recipients as $to_recipient) { 
		if ($to_recipient instanceof ElggUser) {
			mail($to_recipient->email,$subject,$template,cp_get_headers());
			messages_send($subject, $template, $to_recipient->getGUID(), 0);
		}
	}	

	return true;
} // end of function







// this is a mirror image of the core function: /engine/classes/Elgg/PasswordService.php
function cp_send_new_password_request($user_guid) {

	$user_guid = (int)$user_guid;

	$user = _elgg_services()->entityTable->get($user_guid);
	if (!$user instanceof \ElggUser) {
		return false;
	}

	// generate code
	$code = generate_random_cleartext_password();
	$user->setPrivateSetting('passwd_conf_code', $code);
	$user->setPrivateSetting('passwd_conf_time', time());

	// generate link
	$link = _elgg_services()->config->getSiteUrl() . "changepassword?u=$user_guid&c=$code";

	// generate email
	$ip_address = _elgg_services()->request->getClientIp();
	//$message = _elgg_services()->translator->translate('email:changereq:body', array($user->name, $ip_address, $link), $user->language);
	//$subject = _elgg_services()->translator->translate('email:changereq:subject', array(), $user->language);

	$message = array(
		'cp_password_request_user' => $user->username,
		'cp_password_request_ip' => $ip_address,
		'cp_password_request_url' => $link,
		'cp_msg_type' => 'cp_forgot_password',
	);

	$subject = elgg_echo('cp_notify:subject:forgot_password', array(), "en");
	$subject .= ' | '.elgg_echo('cp_notify:subject:forgot_password', array(), "fr");
	$template = elgg_view('cp_notifications/email_template', $message);
	mail($user->email,$subject,$template,cp_get_headers());

}


function cp_get_headers() {
	// reply should always be from GCconnex@tbs-sct.gc.ca (TODO: make setting in admin page)
	$headers = 'From: GCconnex <admin.gcconnex@tbs-sct.gc.ca>' . "\r\n";
	$headers .= 'Reply-To: GCconnex <admin.gcconnex@tbs-sct.gc.ca>' . "\r\n";
	$headers .= 'Return-Path: GCconnex <admin.gcconnex@tbs-sct.gc.ca>' . "\r\n";
	$headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
	$headers .= 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
	return $headers;
}

// membership only notification
function cp_membership_request($event, $type, $object) { // MUST always be sending notification
	$site = elgg_get_site_entity();
	$request_user = get_user($object->guid_one); // user who sends request to join
	$group_request = get_entity($object->guid_two);	// group that is being requested

	$message = array(
					'cp_group_req_user' => $request_user,
					'cp_group_req_group' => $group_request,
					'cp_msg_type' => 'cp_closed_grp_req_type',
				);

	$template = elgg_view('cp_notifications/email_template', $message);
	$subject = elgg_echo('cp_notify:subject:group_request',array($request_user->name, $group_request->name));
	$from_user = elgg_get_site_entity()->name.' <'.'GCconnex@tbs-sct.gc.ca'.'>';
	$to_user = get_user($group_request->owner_guid);

	elgg_send_email($from_user,$to_user->email,$subject,$template); // email
	messages_send($subject, $template, $to_user->guid, 0); // site mail
}


// scans the text object for any @mentions
function cp_scan_mentions($cp_object) {
	$fields = array('title','description','value');
	foreach($fields as $field) {
		$content = $cp_object->$field;	// pull the information from the fields saved to object
		if (preg_match_all("/\@([A-Za-z1-9]*).?([A-Za-z1-9]*)/", $content, $matches)) { // find all the string that matches: @christine.yu

			$users_found = array();
			foreach ($matches[0] as $match) {
				if (preg_match('/\s/',$match)) {
					$user_found = explode(' ',$match);
					$users_found[] = $user_found[0];
				}
			}
			return $users_found;
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
	$do_not_subscribe_list = array('comment','discussion_reply','widget');
	if (elgg_in_context('widgets') || in_array($entity->getSubtype(), $do_not_subscribe_list))
		return $return;

	if ($entity->getContainerEntity() instanceof ElggGroup || $entity instanceof ElggGroup || $entity->getContainerEntity() instanceof ElggUser) {	// only want to receive notification if it's in group or by user
        if(elgg_is_logged_in()){
		    if (check_entity_relationship(elgg_get_logged_in_user_guid(), 'cp_subscribed_to_email', $entity->getGUID())) {
			    // TODO: implement site mail notification too

			    if (elgg_is_active_plugin('wet4')) 
				    $bell_status = '<i class="icon-unsel fa fa-lg fa-bell-slash-o"></i>';
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
				    $bell_status = '<i class="icon-unsel fa fa-lg fa-bell-o"></i>';
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
	}

	return $return;
}