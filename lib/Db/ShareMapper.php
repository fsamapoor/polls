<?php

/**
 * @copyright Copyright (c) 2017 Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 *
 * @author Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 * @author René Gieling <github@dartcafe.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Polls\Db;

use OCA\Polls\Exceptions\ShareNotFoundException;
use OCA\Polls\Model\UserBase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {
	public const TABLE = Share::TABLE;

	public function __construct(
		IDBConnection $db,
		private IConfig $config,
	) {
		parent::__construct($db, Share::TABLE, Share::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 * @return Share[]
	 * @psalm-return array<array-key, Share>
	 */
	public function findByPoll(int $pollId): array {

		$qb = $this->db->getQueryBuilder();
		// Build the following select (MySQL)
		//
		// SELECT *, COUNT(votes.user_id) as voted
		// FROM oc_polls_share shares
		// LEFT JOIN oc_polls_votes votes
		// ON shares.poll_id = votes.poll_id AND shares.user_id = votes.user_id
		// WHERE shares.poll_id = $pollId
		// GROUP BY shares.id
		//

		$qb->select('shares.*')
			->from($this->getTableName(), 'shares')
			->groupBy('shares.id')
			->where($qb->expr()->eq('shares.poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
			->leftJoin(
				'shares',
				Vote::TABLE,
				'votes',
				$qb->expr()->andX(
					$qb->expr()->eq('shares.poll_id', 'votes.poll_id'),
					$qb->expr()->eq('shares.user_id', 'votes.user_id'),
				)
			)
			->addSelect($qb->func()->count('votes.id', 'voted'));

		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 * @return Share[]
	 * @psalm-return array<array-key, Share>
	 */
	public function findByPollNotInvited(int $pollId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('invitation_sent', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 * @return Share[]
	 * @psalm-return array<array-key, Share>
	 */
	public function findByPollUnreminded(int $pollId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reminder_sent', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findByPollAndUser(int $pollId, string $userId): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			throw new ShareNotFoundException("Share not found by userId and pollId");
		}
	}

	/**
	 * Returns a fake share in case of deleted shares
	 */
	public function getReplacement(int $pollId, string $userId): Share {
		$share = new Share;
		$share->setUserId($userId);
		$share->setPollId($pollId);
		$share->setType(UserBase::TYPE_EXTERNAL);
		$share->setToken('deleted_share_' . $userId . '_' . $pollId);

		// TODO: Just a quick fix, differentiate anoymous and deleted users on userGroup base
		if (substr($userId, 0, 9) === 'Anonymous') {
			$share->setDisplayName($userId);
		} else {
			$share->setDisplayName('Deleted User');
		}
		return $share;
	}

	public function findByToken(string $token): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			throw new ShareNotFoundException('Token ' . $token . ' does not exist');
		}
	}

	/**
	 * @return void
	 */
	public function deleteByIdAndType(string $id, string $type): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where('user_id = :id')
			->andWhere('type = :type')
			->setParameter('id', $id)
			->setParameter('type', $type);
		$query->executeStatement();
	}
}
