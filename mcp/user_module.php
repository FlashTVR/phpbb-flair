<?php
/**
 *
 * Profile Flair. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Steve Guidetti, https://github.com/stevotvr
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace stevotvr\flair\mcp;

/**
 * Profile Flair user MCP module.
 */
class user_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container;
		$controller = $phpbb_container->get('stevotvr.flair.controller.mcp.user');
		$request = $phpbb_container->get('request');

		$this->tpl_name = 'mcp_user';
		$this->page_title = 'MCP_FLAIR_MANAGE_USERS';

		$controller->set_page_url($this->u_action);

		$user_id = $request->variable('user_id', 0);
		$username = $request->variable('username', '', true);

		if (!$user_id && !$username)
		{
			$controller->find_user();
			return;
		}

		$controller->edit_user_flair();
	}
}
