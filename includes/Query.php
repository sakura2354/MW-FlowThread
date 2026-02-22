<?php
namespace FlowThread;

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

class Query {
	const FILTER_ALL = 0;
	const FILTER_NORMAL = 1;
	const FILTER_REPORTED = 2;
	const FILTER_DELETED = 3;
	const FILTER_SPAM = 4;

	// Query options
	public $pageid = 0;
	public $user = '';
	public $keyword = '';
	public $dir = 'older';
	public $offset = 0;
	public $limit = -1;
	public $threadMode = true;
	public $filter = self::FILTER_ALL;

	// Query results
	public $totalCount = 0;
	/** @var Post[]|null */
	public $posts = null;

	public function fetch() {
		$dbLoadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $dbLoadBalancer->getConnectionRef(DB_REPLICA);

		$comments = array();
		$parentLookup = array();

		// Start building the query using the modern SelectQueryBuilder
		$queryBuilder = $dbr->newSelectQueryBuilder();
		$queryBuilder->select(Post::getRequiredColumns())
			->from('FlowThread')
			->offset($this->offset)
			->orderBy('flowthread_id', $this->dir === 'older' ? SelectQueryBuilder::SORT_DESC : SelectQueryBuilder::SORT_ASC);

		if ($this->limit !== -1) {
			$queryBuilder->limit($this->limit);
		}

		$conds = [];
		if ($this->pageid) {
			$conds['flowthread_pageid'] = $this->pageid;
		}
		if ($this->user) {
			$conds['flowthread_username'] = $this->user;
		}
		if ($this->keyword) {
			$conds[] = 'flowthread_text' . $dbr->buildLike($dbr->anyString(), $this->keyword, $dbr->anyString());
		}
		if ($this->threadMode) {
			$conds[] = 'flowthread_parentid IS NULL';
		}

		switch ($this->filter) {
		case static::FILTER_ALL:
			break;
		case static::FILTER_NORMAL:
			$conds['flowthread_status'] = Post::STATUS_NORMAL;
			break;
		case static::FILTER_REPORTED:
			$conds['flowthread_status'] = Post::STATUS_NORMAL;
			$conds[] = 'flowthread_report > 0';
			break;
		case self::FILTER_DELETED:
			$conds['flowthread_status'] = Post::STATUS_DELETED;
			break;
		case self::FILTER_SPAM:
			$conds['flowthread_status'] = Post::STATUS_SPAM;
			break;
		}

		$queryBuilder->where($conds);

		// Get all root posts
		$res = $queryBuilder->caller(__METHOD__)->fetchResultSet();

		$binIds = [];
		foreach ($res as $row) {
			$post = Post::newFromDatabaseRow($row);
			$comments[] = $post;
			$parentLookup[$post->id->getBin()] = $post;
			$binIds[] = $post->id->getBin();
		}

		if ($this->threadMode) {
			// Calculate total count using a separate builder to avoid offset/limit issues
			$this->totalCount = $dbr->newSelectQueryBuilder()
				->select('COUNT(*)')
				->from('FlowThread')
				->where($conds)
				->caller(__METHOD__)
				->fetchField();

			// Recursively get all children post list
			// This is not really resource consuming as you might think, as we use IN to boost it up
			while ($binIds) {
				$childConds = array(
					'flowthread_pageid' => $this->pageid,
					'flowthread_parentid' => $binIds, // Modern RDBMS layer handles array as IN() automatically
				);

				switch ($this->filter) {
				case static::FILTER_ALL:
					break;
				default:
					$childConds['flowthread_status'] = Post::STATUS_NORMAL;
					break;
				}

				$childRes = $dbr->newSelectQueryBuilder()
					->select(Post::getRequiredColumns())
					->from('FlowThread')
					->where($childConds)
					->caller(__METHOD__)
					->fetchResultSet();

				$binIds = []; // Clear for next level

				foreach ($childRes as $row) {
					$post = Post::newFromDatabaseRow($row);
					if ($post->parentid) {
						$parentBin = $post->parentid->getBin();
						if (isset($parentLookup[$parentBin])) {
							$post->parent = $parentLookup[$parentBin];
						}
					}

					$comments[] = $post;
					$parentLookup[$post->id->getBin()] = $post;
					$binIds[] = $post->id->getBin();
				}
			}
		}

		$this->posts = $comments;
	}

	public function erase() {
		global $wgTriggerFlowThreadHooks;
		$originalTriggerHooks = $wgTriggerFlowThreadHooks;
		$wgTriggerFlowThreadHooks = false;

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef(DB_PRIMARY);
		
		if ($this->posts) {
			foreach ($this->posts as $post) {
				if ($post->isValid()) {
					$post->eraseSilently($dbw);
				}
			}
		}
		
		$this->posts = array();
		$wgTriggerFlowThreadHooks = $originalTriggerHooks; // Restore original state instead of hard-setting true
	}
}
