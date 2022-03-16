<?php

/**
 * Class-PostChecker.php
 *
 * @package Post Checker
 * @link https://dragomano.ru/mods/post-checker
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2022 Bugo
 * @license https://opensource.org/licenses/MIT MIT
 *
 * @version 0.1
 */

if (!defined('SMF'))
	die('No direct access...');

final class PostChecker
{
	public function hooks()
	{
		add_integration_function('integrate_load_theme', __CLASS__ . '::loadTheme#', false, __FILE__);
		add_integration_function('integrate_admin_areas', __CLASS__ . '::adminAreas#', false, __FILE__);
		add_integration_function('integrate_modify_modifications', __CLASS__ . '::modifyModifications#', false, __FILE__);
		add_integration_function('integrate_post2_pre', __CLASS__ . '::post2Pre#', false, __FILE__);
	}

	/**
	 * @hook integrate_load_theme
	 */
	public function loadTheme()
	{
		loadLanguage('PostChecker');
	}

	/**
	 * @hook integrate_admin_areas
	 */
	public function adminAreas(array &$admin_areas)
	{
		global $txt;

		$admin_areas['config']['areas']['modsettings']['subsections']['post_checker'] = [$txt['pc_title']];
	}

	/**
	 * @hook integrate_modify_modifications
	 */
	public function modifyModifications(array &$subActions)
	{
		$subActions['post_checker'] = [$this, 'settings'];
	}

	public function settings(bool $return_config = false)
	{
		global $context, $txt, $scripturl;

		$context['page_title'] = $context['settings_title'] = $txt['pc_title'];
		$context['post_url'] = $scripturl . '?action=admin;area=modsettings;save;sa=post_checker';
		$context[$context['admin_menu_name']]['tab_data']['description'] = $txt['pc_desc'];

		$config_vars = [
			array('select', 'pc_enable_check_images', array($txt['no'], $txt['pc_allowed'], $txt['pc_disallowed'])),
			array('large_text', 'pc_images_to_check', 5, 'subtext' => $txt['pc_images_to_check_subtext']),
			array('select', 'pc_enable_check_links', array($txt['no'], $txt['pc_allowed'], $txt['pc_disallowed'])),
			array('large_text', 'pc_links_to_check', 5, 'subtext' => $txt['pc_links_to_check_subtext']),
		];

		if ($return_config)
			return $config_vars;

		if (isset($_GET['save'])) {
			checkSession();
			saveDBSettings($config_vars);
			redirectexit('action=admin;area=modsettings;sa=post_checker');
		}

		prepareDBSettingContext($config_vars);
	}

	/**
	 * @hook integrate_post2_pre
	 */
	public function post2Pre(array &$post_errors)
	{
		global $modSettings;

		if (empty($_POST['message']))
			return;

		if (!empty($modSettings['pc_enable_check_images'])) {
			$foundErrors = $this->checkForForbiddenImages($_POST['message']);

			if (count($foundErrors['image']) > 0) {
				$post_errors[] = array('pc_forbidden_image', array(implode(', ', $foundErrors['image'])));
			} else if (!empty($foundErrors['only_allowed_image'])) {
				$post_errors[] = array('pc_only_allowed_image', array(str_replace(PHP_EOL, ', ', $modSettings['pc_images_to_check'])));
			}
		}

		if (!empty($modSettings['pc_enable_check_links'])) {
			$foundErrors = $this->checkForForbiddenLinks($_POST['message']);

			if (count($foundErrors['link']) > 0) {
				$post_errors[] = array('pc_forbidden_link', array(implode(', ', $foundErrors['link'])));
			} else if (!empty($foundErrors['only_allowed_link'])) {
				$post_errors[] = array('pc_only_allowed_link', array(str_replace(PHP_EOL, ', ', $modSettings['pc_links_to_check'])));
			}
		}
	}

	private function checkForForbiddenImages(string $message): array
	{
		global $modSettings, $boardurl;

		$errors['image'] = [];

		if (empty($modSettings['pc_enable_check_images']))
			return $errors;

		$image_hostings_to_check = array_map('trim', explode(PHP_EOL, $modSettings['pc_images_to_check']));

		if ($modSettings['pc_enable_check_images'] === '1') {
			$image_hostings_to_check[] = $boardurl;
			$forbidden_hostings = false;
			preg_match_all('#(\[img\](.+?)\[\/img\])#is', $message, $all_images);
			$count = count($all_images[0]);

			for ($i = 0; $i < $count; $i++) {
				$k = 0;

				foreach ($image_hostings_to_check as $image) {
					if (preg_match('#((\[img\](.*?)' . $image . ')|(\[img=(.*?)' . $image . '))#is', $all_images[0][$i])) {
						$k++;
						break;
					}
				}

				if (empty($k)) {
					$forbidden_hostings = true;
					break;
				}
			}

			if ($count > 0 && $forbidden_hostings)
				$errors['only_allowed_image'] = true;
		}

		if ($modSettings['pc_enable_check_images'] === '2') {
			foreach ($image_hostings_to_check as $image) {
				if (preg_match('#((\[img\](.*?)' . $image . ')|(\[img=(.*?)' . $image . '))#is', $message)) {
					if (!in_array($image, $errors['image']))
						array_push($errors['image'], $image);
				}
			}
		}

		return $errors;
	}

	private function checkForForbiddenLinks(string $message): array
	{
		global $modSettings, $boardurl;

		$errors['link'] = [];

		if (empty($modSettings['pc_enable_check_links']))
			return $errors;

		$links_to_check = array_map('trim', explode(PHP_EOL, $modSettings['pc_links_to_check']));

		if ($modSettings['pc_enable_check_links'] === '1') {
			$links_to_check[] = $boardurl;
			$forbidden_links = false;
			preg_match_all('#((\[url\](.*)\[/url\])|(\[url=([^\]]*)\](.*)\[/url\]))#Usi', $message, $all_links);
			$count = count($all_links[0]);

			for ($i = 0; $i < $count; $i++) {
				$k = 0;

				foreach ($links_to_check as $link) {
					if (preg_match('#((\[url\](.*).' . $link . ')|(\[url\]' . $link . ')|(\[url=(.*).' . $link . ')|(\[url=' . $link . '))#Usi', $all_links[0][$i])) {
						$k++;
						break;
					}
				}

				if (empty($k)) {
					$forbidden_links = true;
					break;
				}
			}

			if ($count > 0 && $forbidden_links)
				$errors['only_allowed_link'] = true;
		}

		if ($modSettings['pc_enable_check_links'] === '2') {
			foreach ($links_to_check as $link) {
				if (preg_match('#((\[url\](.*?).' . $link . ')|(\[url\]' . $link . ')|(\[url=(.*?).' . $link . ')|(\[url=' . $link . '))#Usi', $message)) {
					if (!in_array($link, $errors['link']))
						array_push($errors['link'], $link);
				}
			}
		}

		return $errors;
	}
}
