<?php

/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Group;

use OC\Hooks\PublicEmitter;

/**
 * Class Manager
 *
 * Hooks available in scope \OC\Group:
 * - preAddUser(\OC\Group\Group $group, \OC\User\User $user)
 * - postAddUser(\OC\Group\Group $group, \OC\User\User $user)
 * - preRemoveUser(\OC\Group\Group $group, \OC\User\User $user)
 * - postRemoveUser(\OC\Group\Group $group, \OC\User\User $user)
 * - preDelete(\OC\Group\Group $group)
 * - postDelete(\OC\Group\Group $group)
 * - preCreate(string $groupId)
 * - postCreate(\OC\Group\Group $group)
 *
 * @package OC\Group
 */
class Manager extends PublicEmitter {
	/**
	 * @var \OC_Group_Backend[] | \OC_Group_Database[] $backends
	 */
	private $backends = array();

	/**
	 * @var \OC\User\Manager $userManager
	 */
	private $userManager;

	/**
	 * @var \OC\Group\Group[]
	 */
	private $cachedGroups;

	/**
	 * @param \OC\User\Manager $userManager
	 */
	public function __construct($userManager) {
		$this->userManager = $userManager;
		$cache = & $this->cachedGroups;
		$this->listen('\OC\Group', 'postDelete', function ($group) use (&$cache) {
			/**
			 * @var \OC\Group\Group $group
			 */
			unset($cache[$group->getGID()]);
		});
	}

	/**
	 * @param \OC_Group_Backend $backend
	 */
	public function addBackend($backend) {
		$this->backends[] = $backend;
	}

	public function clearBackends() {
		$this->backends = array();
		$this->cachedGroups = array();
	}

	/**
	 * @param string $gid
	 * @return \OC\Group\Group
	 */
	public function get($gid) {
		if (isset($this->cachedGroups[$gid])) {
			return $this->cachedGroups[$gid];
		}
		return $this->getGroupObject($gid);
	}

	protected function getGroupObject($gid) {
		$backends = array();
		foreach ($this->backends as $backend) {
			if ($backend->groupExists($gid)) {
				$backends[] = $backend;
			}
		}
		if (count($backends) === 0) {
			return null;
		}
		$this->cachedGroups[$gid] = new Group($gid, $backends, $this->userManager, $this);
		return $this->cachedGroups[$gid];
	}

	/**
	 * @param string $gid
	 * @return bool
	 */
	public function groupExists($gid) {
		return !is_null($this->get($gid));
	}

	/**
	 * @param string $gid
	 * @return \OC\Group\Group
	 */
	public function createGroup($gid) {
		if (!$gid) {
			return false;
		} else if ($group = $this->get($gid)) {
			return $group;
		} else {
			$this->emit('\OC\Group', 'preCreate', array($gid));
			foreach ($this->backends as $backend) {
				if ($backend->implementsActions(OC_GROUP_BACKEND_CREATE_GROUP)) {
					$backend->createGroup($gid);
					$group = $this->getGroupObject($gid);
					$this->emit('\OC\Group', 'postCreate', array($group));
					return $group;
				}
			}
			return null;
		}
	}

	/**
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\Group\Group[]
	 */
	public function search($search, $limit = null, $offset = null) {
		$groups = array();
		foreach ($this->backends as $backend) {
			$groupIds = $backend->getGroups($search, $limit, $offset);
			if (!is_null($limit)) {
				$limit -= count($groupIds);
			}
			if (!is_null($offset)) {
				$offset -= count($groupIds);
			}
			foreach ($groupIds as $groupId) {
				$groups[$groupId] = $this->getGroupObject($groupId);
			}
			if (!is_null($limit) and $limit <= 0) {
				return array_values($groups);
			}
		}
		return array_values($groups);
	}

	/**
	 * @param \OC\User\User $user
	 * @return \OC\Group\Group[]
	 */
	public function getUserGroups($user) {
		$groups = array();
		foreach ($this->backends as $backend) {
			$groupIds = $backend->getUserGroups($user->getUID());
			foreach ($groupIds as $groupId) {
				$groups[$groupId] = $this->getGroupObject($groupId);
			}
		}
		return array_values($groups);
	}
}
