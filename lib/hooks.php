<?php
/**
 * all plugin hooks for this plugin are bundled here
 */

/**
 * Extends thewire pagehandler with some extra pages
 *
 * @param string $hook_name   'route'
 * @param string $entity_type 'thewire'
 * @param bool   $return      the default return value
 * @param array  $params      supplied params
 *
 * @return bool
 */
function thewire_tools_route_thewire($hook_name, $entity_type, $return, $params) {
	$page = elgg_extract("segments", $return);
	
	if (is_array($page)) {
		switch ($page[0]) {
			case "group":
				if (!empty($page[1])) {
					set_input("group_guid", $page[1]); // @todo is this still needed or replace with page_owner in page
						
					if (!empty($page[2])) {
						set_input("wire_username", $page[2]); // @todo is this still needed?
					}
						
					$include_file = "pages/group.php";
					break;
				}
			case "tag":
			case "search":
				if (isset($page[1])) {
					if ($page[0] == "tag") {
						set_input("query", "#" . $page[1]);
					} else {
						set_input("query", $page[1]);
					}
				}
				
				$include_file = "pages/search.php";
				break;
			case "autocomplete":
				$include_file = "procedures/autocomplete.php";
				break;
			case "conversation":
				if (isset($page[1])) {
					set_input("guid", $page[1]);
				}
				$include_file = "procedures/conversation.php";
				break;
			case "reply":
			case "thread":
				if (!empty($page[1])) {
					$entity = get_entity($page[1]);
					
					if (!empty($entity) && elgg_instanceof($entity->getContainerEntity(), "group")) {
						elgg_set_page_owner_guid($entity->getContainerGUID());
					}
				}
				break;
			
		}
		
		if (!empty($include_file)) {
			include(dirname(dirname(__FILE__)) . "/" . $include_file);
			
			$return = false;
		}
		
	}
	
	return $return;
}

/**
 * Optionally extend the group owner block with a link to the wire posts of the group
 *
 * @param string         $hook_name   'register'
 * @param string         $entity_type 'menu:owner_block'
 * @param ElggMenuItem[] $return      all the current menu items
 * @param array          $params      supplied params
 *
 * @return ElggMenuItem[]
 */
function thewire_tools_owner_block_menu($hook_name, $entity_type, $return, $params) {
	
	$group = elgg_extract("entity", $params);
	if (elgg_instanceof($group, "group") && $group->thewire_enable != "no") {
		$url = "thewire/group/" . $group->getGUID();
		$item = new ElggMenuItem("thewire", elgg_echo("thewire_tools:group:title"), $url);
		$return[] = $item;
	}
	
	return $return;
}

/**
 * Provide a custom access pulldown for use on personal wire posts
 *
 * @param string $hook_name   'access:collections:write'
 * @param string $entity_type 'all'
 * @param array  $return      the current access options
 * @param array  $params      supplied params
 *
 * @return array
 */
function thewire_tools_access_write_hook($hook_name, $entity_type, $return, $params) {
	
	$user = elgg_get_logged_in_user_entity();
	if (elgg_in_context("thewire") && !empty($user)) {
		if (is_array($return)) {
			unset($return[ACCESS_PRIVATE]);
			unset($return[ACCESS_FRIENDS]);
			
			$options = array(
				"type" => "group",
				"limit" => false,
				"relationship" => "member",
				"relationship_guid" => $user->getGUID()
			);
			
			$groups = elgg_get_entities_from_relationship($options);
			if (!empty($groups)) {
				foreach ($groups as $group) {
					if ($group->thewire_enable !== "no") {
						$return[$group->group_acl] = $group->name;
					}
				}
			}
		}
	}
	
	return $return;
}

/**
 * Improves entity menu items for thewire objects
 *
 * @param string         $hook_name   'register'
 * @param string         $entity_type 'menu:entity'
 * @param ElggMenuItem[] $return      the current menu items
 * @param array          $params      supplied params
 *
 * @return ElggMenuItem[]
 */
function thewire_tools_register_entity_menu_items($hook_name, $entity_type, $return, $params) {
	
	if (!empty($params) && is_array($params)) {
		$entity = elgg_extract("entity", $params, false);
		
		if (!empty($entity) && is_array($return)) {
			if (elgg_instanceof($entity, "object", "thewire")) {
				
				foreach ($return as $index => $menu_item) {
					if ($menu_item->getName() == "thread") {
						//removes thread link from thewire entity menu if there is no conversation
						if (!($entity->countEntitiesFromRelationship("parent") || $entity->countEntitiesFromRelationship("parent", true))) {
							unset($return[$index]);
						}
					}
				}
			}
			
			// add reshare options
			if (elgg_instanceof($entity, "object")) {
				elgg_load_js("lightbox");
				elgg_load_css("lightbox");
				
				$postfix = "";
				$reshare_guid = $entity->getGUID();
				$reshare = $entity->getEntitiesFromRelationship(array("relationship" => "reshare", "limit" => 1));
				if (!empty($reshare)) {
					// this is a wire post which is a reshare, so link to original object
					$reshare_guid = $reshare[0]->getGUID();
				} else {
					// check is this item was shared on thewire
					$count = $entity->getEntitiesFromRelationship(array(
						"type" => "object",
						"subtype" => "thewire",
						"inverse_relationship" => true,
						"count" => true
					));
					
					if ($count) {
						// show counter
						$postfix = "<span class='float-alt'>" . $count . "</span>";
					}
				}
				
				$return[] = ElggMenuItem::factory(array(
					"name" => "thewire_tools_reshare",
					"text" => elgg_view_icon("share") . $postfix,
					"title" => elgg_echo("thewire_tools:reshare"),
					"href" => "ajax/view/thewire_tools/reshare?reshare_guid=" . $reshare_guid,
					"link_class" => "elgg-lightbox",
					"is_trusted" => true,
					"priority" => 500
				));
			}
		}
	}
	
	return $return;
}

/**
 * Add wire reply link to river wire entities
 *
 * @param string         $hook_name   'register'
 * @param string         $entity_type 'menu:river'
 * @param ElggMenuItem[] $return      the current menu items
 * @param array          $params      supplied params
 *
 * @return ElggMenuItem[]
 */
function thewire_tools_register_river_menu_items($hook_name, $entity_type, $return, $params) {
	$entity = $params["item"]->getObjectEntity();

	if (elgg_is_logged_in() && !empty($entity) && elgg_instanceof($entity, "object", "thewire")) {
		if (!is_array($return)) {
			$return = array();
		}
		$options = array(
			"name" => "reply",
			"text" => elgg_echo("reply"),
			"href" => "thewire/reply/" . $entity->getGUID(),
			"priority" => 150,
		);
		$return[] = ElggMenuItem::factory($options);
	}
	
	return $return;
}

/**
 * Forwards thewire delete action back to referer
 *
 * @param string $hook_name   'forward'
 * @param string $entity_type 'all'
 * @param string $return      the current forward url
 * @param array  $params      supplied params
 *
 * @return string the forward url
 */
function thewire_tools_forward_hook($hook_name, $entity_type, $return, $params) {
	
	if (get_input("action") == "thewire/delete") {
		$return = $_SERVER["HTTP_REFERER"];
	}
	
	return $return;
}

/**
 * returns the correct widget title
 *
 * @param string $hook_name   'widget_url'
 * @param string $entity_type 'widget_manager'
 * @param string $return      the current widget url
 * @param array  $params      supplied params
 *
 * @return string the url for the widget
 */
function thewire_tools_widget_title_url($hook_name, $entity_type, $return, $params) {
	$result = $return;
	
	if (empty($result) && !empty($params) && is_array($params)) {
		$widget = $params["entity"];
		
		if ($widget instanceof ElggWidget) {
			switch ($widget->handler) {
				case "thewire":
					$result = "thewire/owner/" . $widget->getOwnerEntity()->username;
					break;
				case "index_thewire":
				case "thewire_post":
					$result = "thewire/all";
					break;
				case "thewire_groups":
					$result = "thewire/group/" . $widget->getOwnerGUID();
					break;
			}
		}
	}
	
	return $result;
}

/**
 * returns the correct url for a wire object
 *
 * @param string $hook_name   'entity:url'
 * @param string $entity_type 'object'
 * @param string $return      the current entity url
 * @param array  $params      supplied params
 *
 * @return string the url for the widget
 */
function thewire_tools_entity_url_handler($hook_name, $entity_type, $return, $params) {
	
	if (!empty($params) && is_array($params)) {
		$entity = elgg_extract("entity", $params);
		
		if (!empty($entity) && elgg_instanceof($entity, "object", "thewire")) {
			if ($entity->getContainerEntity() instanceof ElggGroup) {
				$return = "thewire/group/" . $entity->getContainerGUID();
			} else {
				$return = "thewire/owner/" . $entity->getOwnerEntity()->username;
			}
		}
	}
	
	return $return;
}
