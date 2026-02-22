<?php
namespace FlowThread;

use Exception;
use MediaWiki\Content\Content;
use MediaWiki\Logging\LogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Skin\BaseTemplate;
use MediaWiki\Skin\Skin;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
// 移除对具体 User 类的强制依赖，改为接口以增强兼容性
use MediaWiki\User\UserIdentity; 

class Hooks {

	// 修复：移除 &$skin 的引用符号，新版 Hook 传参不再建议对对象使用引用
	public static function onBeforePageDisplay(OutputPage &$output, Skin $skin) {
		$title = $output->getTitle();

		if (!Helper::canEverPostOnTitle($title)) {
			return true;
		}

		if ($output->isPrintable()) {
			return true;
		}

		if ($skin->getRequest()->getVal('action', 'view') != 'view') {
			return true;
		}

		if (self::getPermissionManager()->userHasRight($output->getUser(), 'commentadmin-restricted')) {
			$output->addJsConfigVars(array('commentadmin' => ''));
		}

		global $wgFlowThreadConfig;
		$config = array(
			'Avatar' => $wgFlowThreadConfig['Avatar'],
			'AnonymousAvatar' => $wgFlowThreadConfig['AnonymousAvatar'],
		);

		if (!Post::canPost($output->getUser())) {
			$config['CantPostNotice'] = wfMessage('flowthread-ui-cantpost')->parse();
		} else {
			$status = SpecialControl::getControlStatus($title);
			if ($status === SpecialControl::STATUS_OPTEDOUT) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-useroptout')->parse();
			} else if ($status === SpecialControl::STATUS_DISABLED) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-disabled')->parse();
			} else {
				$output->addJsConfigVars(array('canpost' => ''));
			}
		}

		$output->addJsConfigVars(array('wgFlowThreadConfig' => $config));
		$output->addModules('ext.flowthread');
		return true;
	}

	public static function onLoadExtensionSchemaUpdates($updater) {
		$dir = __DIR__ . '/../sql';

		$dbType = $updater->getDB()->getType();
		if (!in_array($dbType, array('mysql', 'sqlite'))) {
			throw new Exception('Database type not currently supported');
		} else {
			$filename = 'mysql.sql';
		}

		$updater->addExtensionTable('FlowThread', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadAttitude', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadControl', "{$dir}/control.sql");

		return true;
	}

	/**
	 * 核心修复：解决删除页面时的 TypeError
	 * 1. 移除 &$article 的引用声明
	 * 2. 将 User &$user 改为 $user (兼容 UserIdentity)
	 * 3. 增加 Throwable 捕获，防止插件错误导致系统无法删除页面
	 */
	public static function onArticleDeleteComplete($article, $user, $reason, $id, $content = null, $logEntry = null) {
		try {
			$page = new Query();
			$page->pageid = (int)$id;
			$page->limit = -1;
			$page->threadMode = false;
			$page->fetch();
			$page->erase();
		} catch (\Throwable $e) {
			// 静默处理错误，确保页面删除成功
			if (defined('MW_DEBUG')) {
				throw $e;
			}
		}
		return true;
	}

	public static function onBaseTemplateToolbox(BaseTemplate &$baseTemplate, array &$toolbox) {
		if (isset($baseTemplate->data['nav_urls']['usercomments'])
			&& $baseTemplate->data['nav_urls']['usercomments']) {
			$toolbox['usercomments'] = $baseTemplate->data['nav_urls']['usercomments'];
			$toolbox['usercomments']['id'] = 't-usercomments';
		}
	}

	// 修复：移除 &$sidebar 的引用限制
	public static function onSidebarBeforeOutput(Skin $skin, &$sidebar) {
		$commentAdmin = self::getPermissionManager()->userHasRight($skin->getUser(), 'commentadmin-restricted');
		$user = $skin->getRelevantUser();

		if ($user && $commentAdmin) {
			$sidebar['TOOLBOX'][] = [
				'text' => wfMessage('sidebar-usercomments')->text(),
				'href' => SpecialPage::getTitleFor('FlowThreadManage')->getLocalURL(array(
					'user' => $user->getName(),
				)),
			];
		}
	}

	public static function onSkinTemplateNavigation_Universal(SkinTemplate $skinTemplate, array &$links) {
		$commentAdmin = self::getPermissionManager()->userHasRight($skinTemplate->getUser(), 'commentadmin-restricted');
		$user = $skinTemplate->getRelevantUser();

		$title = $skinTemplate->getRelevantTitle();
		// 修复：确保 Title 对象存在且调用正确
		if ($title && Helper::canEverPostOnTitle($title) && ($commentAdmin || Post::userOwnsPage($skinTemplate->getUser(), $title))) {
			$links['actions']['flowthreadcontrol'] = [
				'id' => 'ca-flowthreadcontrol',
				'text' => wfMessage('action-flowthreadcontrol')->text(),
				'href' => SpecialPage::getTitleFor('FlowThreadControl', $title->getPrefixedDBKey())->getLocalURL()
			];
		}

		return true;
	}

	private static function getPermissionManager() : PermissionManager {
		return MediaWikiServices::getInstance()->getPermissionManager();
	}
}
